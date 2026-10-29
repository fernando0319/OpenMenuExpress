<?php

namespace App\Services\Tables;

use App\Models\Tab;

/**
 * The bill of a tab: what was consumed, the service fee (optional for the customer) and a discount.
 */
final readonly class TabBill
{
    public function __construct(
        public int $subtotalCents,
        public int $serviceFeePercent,
        public int $serviceFeeCents,
        public int $discountCents,
    ) {}

    public static function for(Tab $tab, bool $withServiceFee = true, int $discountCents = 0): self
    {
        $subtotal = $tab->consumedCents();
        $percent = $withServiceFee ? $tab->store->service_fee_percent : 0;

        // Round half up, like a calculator.
        $fee = intdiv($subtotal * $percent + 50, 100);

        return new self($subtotal, $percent, $fee, min($discountCents, $subtotal + $fee));
    }

    public function totalCents(): int
    {
        return $this->subtotalCents + $this->serviceFeeCents - $this->discountCents;
    }

    /**
     * @return array{subtotalCents: int, serviceFeePercent: int, serviceFeeCents: int, discountCents: int, totalCents: int}
     */
    public function toArray(): array
    {
        return [
            'subtotalCents' => $this->subtotalCents,
            'serviceFeePercent' => $this->serviceFeePercent,
            'serviceFeeCents' => $this->serviceFeeCents,
            'discountCents' => $this->discountCents,
            'totalCents' => $this->totalCents(),
        ];
    }
}
