<?php

namespace App\Services\Orders;

use App\Enums\DeliveryFeeMode;
use App\Enums\Fulfillment;
use App\Enums\OnlinePaymentMethod;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderPlaced;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Store;
use App\Payments\OnlinePayments;
use App\Services\Coupons\CouponService;
use App\Services\Payments\StorePaymentMethods;
use App\Services\Scheduling\StoreSchedule;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns what the customer sent from the menu into an order.
 * Every price, rule and fee is recomputed here; the browser's totals are never trusted.
 *
 * @phpstan-import-type ItemInput from CartPricer
 *
 * @phpstan-type OrderInput array{
 *     fulfillment: string,
 *     customer: array{name: string, phone: string},
 *     address?: array{zip?: string|null, street?: string|null, number?: string|null, district?: string|null, complement?: string|null, reference?: string|null},
 *     delivery_zone_id?: int|null,
 *     payment_method: string,
 *     change_for?: string|null,
 *     no_change?: bool,
 *     notes?: string|null,
 *     scheduled_for?: string|null,
 *     coupon_code?: string|null,
 *     items: list<ItemInput>
 * }
 */
final class PlaceOnlineOrder
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly OrderRecorder $recorder,
        private readonly CustomerBook $customers,
        private readonly CouponService $coupons,
        private readonly StorePaymentMethods $paymentMethods,
        private readonly OnlinePayments $onlinePayments,
    ) {}

    /**
     * @param  OrderInput  $input
     */
    public function handle(Store $store, array $input): Order
    {
        if ($store->is_paused) {
            $this->fail('store', 'A loja está fechada no momento. Tente novamente mais tarde.');
        }

        $scheduledFor = $this->scheduledFor($store, $input['scheduled_for'] ?? null);

        $fulfillment = $this->fulfillment($store, $input['fulfillment']);
        $lines = $this->pricer->price($store, $input['items']);
        $subtotal = CartPricer::subtotal($lines);

        if ($subtotal < $store->minimum_order_cents) {
            $this->fail('items', 'O pedido mínimo é de R$ '.Money::toInput($store->minimum_order_cents).' (sem a entrega).');
        }

        $zone = $fulfillment === Fulfillment::Delivery ? $this->deliveryZone($store, $input['delivery_zone_id'] ?? null) : null;
        $deliveryFee = $zone !== null ? $zone->fee_cents : 0;
        // Paid now in the app (Pix, card, Bitcoin) or later, on delivery / at the counter.
        $online = OnlinePaymentMethod::tryFrom($input['payment_method']);
        $account = $online !== null ? $this->onlinePayments->accountFor($store, $online) : null;
        $paymentMethod = $online === null ? PaymentMethod::from($input['payment_method']) : null;

        if ($online !== null && $account === null) {
            $this->fail('payment_method', 'Este pagamento online não está disponível nesta loja. Escolha outra forma de pagamento.');
        }

        if ($paymentMethod !== null && ! $this->paymentMethods->accepts($store, $paymentMethod)) {
            $this->fail('payment_method', "Esta loja não aceita {$paymentMethod->label()}. Escolha outra forma de pagamento.");
        }

        $couponCode = trim((string) ($input['coupon_code'] ?? ''));

        $order = DB::transaction(function () use ($store, $input, $fulfillment, $lines, $subtotal, $zone, $deliveryFee, $paymentMethod, $online, $scheduledFor, $couponCode): Order {
            $this->pricer->reserveStock($lines);

            $address = $fulfillment === Fulfillment::Delivery ? ($input['address'] ?? []) : [];
            $customer = $this->customers->remember($store, $input['customer']['name'], $input['customer']['phone'], $address);

            // Locked evaluation: usage limits hold even with simultaneous orders.
            $applied = $couponCode === ''
                ? null
                : $this->coupons->evaluate($store, $couponCode, $subtotal, $deliveryFee, $fulfillment, $customer, lock: true);
            $discount = $applied === null ? 0 : $applied->discountCents;
            $total = $subtotal + $deliveryFee - $discount;
            $changeFor = $paymentMethod !== null ? $this->changeFor($paymentMethod, $input['change_for'] ?? null, (bool) ($input['no_change'] ?? false), $total) : null;

            $order = $this->recorder->record($store, [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'channel' => OrderChannel::Online,
                'fulfillment' => $fulfillment,
                'delivery_zip' => $address['zip'] ?? null,
                'delivery_street' => $address['street'] ?? null,
                'delivery_number' => $address['number'] ?? null,
                'delivery_district' => ($address['district'] ?? null) ?: $zone?->name,
                'delivery_complement' => $address['complement'] ?? null,
                'delivery_reference' => $address['reference'] ?? null,
                'delivery_zone_id' => $zone?->id,
                'coupon_id' => $applied?->coupon->id,
                'coupon_code' => $applied?->coupon->code,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discount,
                'delivery_fee_cents' => $deliveryFee,
                'total_cents' => $total,
                'payment_method' => $online->value ?? $paymentMethod?->value,
                'change_for_cents' => $changeFor,
                'notes' => $input['notes'] ?? null,
                'scheduled_for' => $scheduledFor,
            ], $lines, $online !== null ? OrderStatus::PendingPayment : OrderStatus::Pending);

            if ($applied !== null) {
                $applied->coupon->redemptions()->create([
                    'order_id' => $order->id,
                    'customer_id' => $customer->id,
                    'discount_cents' => $discount,
                ]);
            }

            return $order;
        });

        // Paid online: the store only gets the order once the payment is confirmed.
        if ($online !== null) {
            $this->onlinePayments->start($order, $online, $account);

            return $order;
        }

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * A booked time must be one of the slots the menu offers right now. Without one,
     * the store has to be open at this moment.
     */
    private function scheduledFor(Store $store, ?string $value): ?CarbonImmutable
    {
        $schedule = StoreSchedule::for($store);
        $now = CarbonImmutable::now();

        if ($value === null || trim($value) === '') {
            if (! $schedule->isOpenAt($now)) {
                $this->fail('store', $store->accepts_scheduling
                    ? 'A loja está fechada agora. Escolha um horário para agendar o pedido.'
                    : 'A loja está fechada agora. Volte no horário de funcionamento.');
            }

            return null;
        }

        if (! $store->accepts_scheduling) {
            $this->fail('scheduled_for', 'Esta loja não aceita pedidos agendados.');
        }

        try {
            $slot = CarbonImmutable::parse($value)->utc();
        } catch (\Throwable) {
            $this->fail('scheduled_for', 'Horário de agendamento inválido.');
        }

        if (! $schedule->isAvailableSlot($slot, $now)) {
            $this->fail('scheduled_for', 'Esse horário não está mais disponível. Escolha outro.');
        }

        return $slot;
    }

    private function fulfillment(Store $store, string $value): Fulfillment
    {
        $fulfillment = Fulfillment::tryFrom($value);

        $accepted = match ($fulfillment) {
            Fulfillment::Delivery => $store->accepts_delivery,
            Fulfillment::Pickup => $store->accepts_pickup,
            default => false,
        };

        if ($fulfillment === null || ! $accepted) {
            $this->fail('fulfillment', 'Esta forma de entrega não está disponível.');
        }

        return $fulfillment;
    }

    private function deliveryZone(Store $store, ?int $zoneId): DeliveryZone
    {
        if ($store->delivery_fee_mode !== DeliveryFeeMode::Zone) {
            $this->fail('fulfillment', 'A entrega por distância ainda não está disponível. Escolha retirada.');
        }

        $zone = $zoneId === null ? null : $store->deliveryZones()->where('is_active', true)->find($zoneId);

        if (! $zone instanceof DeliveryZone) {
            $this->fail('delivery_zone_id', 'Escolha o seu bairro ou região de entrega.');
        }

        return $zone;
    }

    /**
     * Paying cash, the customer must say how much change to bring, or that none is needed:
     * a courier leaving without change is the most common delivery complaint.
     */
    private function changeFor(PaymentMethod $method, ?string $value, bool $noChange, int $total): ?int
    {
        if ($method !== PaymentMethod::Cash || $noChange) {
            return null;
        }

        if ($value === null || trim($value) === '') {
            $this->fail('change_for', 'Informe para quanto precisa de troco, ou marque "Não preciso de troco".');
        }

        $cents = Money::parseToCents($value);

        if ($cents === null || $cents < $total) {
            $this->fail('change_for', 'O troco deve ser para um valor maior ou igual ao total de R$ '.Money::toInput($total).'.');
        }

        return $cents;
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
