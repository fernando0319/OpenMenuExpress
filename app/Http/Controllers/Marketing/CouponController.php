<?php

namespace App\Http\Controllers\Marketing;

use App\Enums\CouponType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\CouponRequest;
use App\Models\Coupon;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CouponController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(): Response
    {
        $store = $this->currentStore();

        $coupons = $store->coupons()
            ->withCount('redemptions')
            ->latest('id')
            ->get()
            ->map(fn (Coupon $coupon) => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'description' => $coupon->description,
                'benefit' => $this->benefit($coupon),
                'uses' => $coupon->getAttribute('redemptions_count'),
                'usageLimit' => $coupon->usage_limit,
                'status' => $this->status($coupon, (int) $coupon->getAttribute('redemptions_count')),
                'endsAt' => $coupon->ends_at?->setTimezone($store->timezone)->format('d/m/Y H:i'),
            ]);

        return Inertia::render('marketing/coupons/Index', ['coupons' => $coupons]);
    }

    public function create(): Response
    {
        return Inertia::render('marketing/coupons/Form', [
            'coupon' => null,
            'types' => $this->types(),
        ]);
    }

    public function store(CouponRequest $request): RedirectResponse
    {
        $this->currentStore()->coupons()->create($request->couponData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cupom criado.']);

        return to_route('marketing.coupons.index');
    }

    public function edit(Coupon $coupon): Response
    {
        $this->ensureBelongsToCurrentStore($coupon);
        $timezone = $this->currentStore()->timezone;

        return Inertia::render('marketing/coupons/Form', [
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'description' => $coupon->description,
                'type' => $coupon->type->value,
                'percent' => $coupon->percent,
                'amount' => Money::toInput($coupon->amount_cents),
                'max_discount' => Money::toInput($coupon->max_discount_cents),
                'min_subtotal' => $coupon->min_subtotal_cents > 0 ? Money::toInput($coupon->min_subtotal_cents) : '',
                'usage_limit' => $coupon->usage_limit,
                'per_customer_limit' => $coupon->per_customer_limit,
                'first_order_only' => $coupon->first_order_only,
                'fulfillment' => $coupon->fulfillment->value ?? '',
                'starts_at' => $coupon->starts_at?->setTimezone($timezone)->format('Y-m-d\TH:i') ?? '',
                'ends_at' => $coupon->ends_at?->setTimezone($timezone)->format('Y-m-d\TH:i') ?? '',
                'is_active' => $coupon->is_active,
            ],
            'types' => $this->types(),
        ]);
    }

    public function update(CouponRequest $request, Coupon $coupon): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($coupon);

        $coupon->update($request->couponData());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cupom atualizado.']);

        return to_route('marketing.coupons.index');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($coupon);

        // Orders keep the code and discount they had (coupon_id becomes null).
        $coupon->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cupom excluído.']);

        return to_route('marketing.coupons.index');
    }

    private function benefit(Coupon $coupon): string
    {
        $text = match ($coupon->type) {
            CouponType::Percent => "{$coupon->percent}%".($coupon->max_discount_cents !== null ? ' (até R$ '.Money::toInput($coupon->max_discount_cents).')' : ''),
            CouponType::Fixed => 'R$ '.Money::toInput($coupon->amount_cents),
            CouponType::FreeDelivery => 'Frete grátis',
        };

        return $coupon->min_subtotal_cents > 0
            ? $text.' acima de R$ '.Money::toInput($coupon->min_subtotal_cents)
            : $text;
    }

    private function status(Coupon $coupon, int $uses): string
    {
        $now = CarbonImmutable::now();

        return match (true) {
            ! $coupon->is_active => 'Inativo',
            $coupon->ends_at !== null && $coupon->ends_at->lessThanOrEqualTo($now) => 'Expirado',
            $coupon->usage_limit !== null && $uses >= $coupon->usage_limit => 'Esgotado',
            $coupon->starts_at !== null && $coupon->starts_at->greaterThan($now) => 'Agendado',
            default => 'Ativo',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function types(): array
    {
        return array_map(fn (CouponType $type) => ['value' => $type->value, 'label' => $type->label()], CouponType::cases());
    }
}
