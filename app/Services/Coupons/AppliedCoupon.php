<?php

namespace App\Services\Coupons;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\Money;

final readonly class AppliedCoupon
{
    public function __construct(
        public Coupon $coupon,
        public int $discountCents,
    ) {}

    public function label(): string
    {
        return match ($this->coupon->type) {
            CouponType::FreeDelivery => 'Frete grátis',
            CouponType::Percent => "{$this->coupon->percent}% de desconto",
            CouponType::Fixed => 'R$ '.Money::toInput($this->discountCents).' de desconto',
        };
    }
}
