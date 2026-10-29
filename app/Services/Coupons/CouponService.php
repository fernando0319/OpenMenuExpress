<?php

namespace App\Services\Coupons;

use App\Enums\CouponType;
use App\Enums\Fulfillment;
use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Store;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Checks a coupon against the order and computes the discount.
 * At checkout it runs inside the order transaction with the coupon row locked, so usage
 * limits hold even when orders arrive at the same time.
 */
final class CouponService
{
    /**
     * @param  int  $subtotalCents  Products only (no delivery).
     *
     * @throws ValidationException under the "coupon" key, with a message for the customer.
     */
    public function evaluate(
        Store $store,
        string $code,
        int $subtotalCents,
        int $deliveryFeeCents,
        ?Fulfillment $fulfillment,
        ?Customer $customer,
        bool $lock = false,
    ): AppliedCoupon {
        $coupon = $store->coupons()
            ->where('code', Coupon::normalizeCode($code))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $coupon instanceof Coupon || ! $coupon->is_active) {
            $this->fail('Cupom não encontrado ou inativo.');
        }

        $now = now();

        if ($coupon->starts_at !== null && $coupon->starts_at->greaterThan($now)) {
            $this->fail('Este cupom ainda não começou a valer.');
        }

        if ($coupon->ends_at !== null && $coupon->ends_at->lessThanOrEqualTo($now)) {
            $this->fail('Este cupom expirou.');
        }

        if ($coupon->fulfillment !== null && $fulfillment !== null && $coupon->fulfillment !== $fulfillment) {
            $this->fail("Este cupom vale só para {$this->fulfillmentWord($coupon->fulfillment)}.");
        }

        if ($coupon->type === CouponType::FreeDelivery && $fulfillment === Fulfillment::Pickup) {
            $this->fail('Este cupom é de frete grátis e vale só para entrega.');
        }

        if ($subtotalCents < $coupon->min_subtotal_cents) {
            $this->fail('Este cupom vale para pedidos a partir de R$ '.Money::toInput($coupon->min_subtotal_cents).'.');
        }

        if ($coupon->usage_limit !== null && $coupon->redemptions()->count() >= $coupon->usage_limit) {
            $this->fail('Este cupom esgotou.');
        }

        if ($customer !== null) {
            $this->checkCustomer($coupon, $customer);
        }

        return new AppliedCoupon($coupon, $this->discount($coupon, $subtotalCents, $deliveryFeeCents));
    }

    private function checkCustomer(Coupon $coupon, Customer $customer): void
    {
        if ($coupon->per_customer_limit !== null
            && $coupon->redemptions()->where('customer_id', $customer->id)->count() >= $coupon->per_customer_limit) {
            $this->fail('Você já usou este cupom.');
        }

        if ($coupon->first_order_only
            && $customer->orders()->whereNotIn('status', [OrderStatus::Cancelled, OrderStatus::Refunded])->exists()) {
            $this->fail('Este cupom vale só na primeira compra.');
        }
    }

    private function discount(Coupon $coupon, int $subtotalCents, int $deliveryFeeCents): int
    {
        $discount = match ($coupon->type) {
            CouponType::Percent => intdiv($subtotalCents * (int) $coupon->percent, 100),
            CouponType::Fixed => (int) $coupon->amount_cents,
            CouponType::FreeDelivery => $deliveryFeeCents,
        };

        if ($coupon->type === CouponType::Percent && $coupon->max_discount_cents !== null) {
            $discount = min($discount, $coupon->max_discount_cents);
        }

        // Never more than what is being paid for: products, plus delivery when the coupon is about delivery.
        $ceiling = $coupon->type === CouponType::FreeDelivery ? $deliveryFeeCents : $subtotalCents;

        return max(0, min($discount, $ceiling));
    }

    private function fulfillmentWord(Fulfillment $fulfillment): string
    {
        return match ($fulfillment) {
            Fulfillment::Delivery => 'entrega',
            Fulfillment::Pickup => 'retirada',
            Fulfillment::DineIn => 'pedidos na mesa',
        };
    }

    /**
     * @return never
     */
    private function fail(string $message): void
    {
        throw ValidationException::withMessages(['coupon' => $message]);
    }
}
