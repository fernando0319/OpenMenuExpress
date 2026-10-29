<?php

namespace App\Services\Pricing;

final readonly class ItemPrice
{
    public function __construct(
        public int $unitBaseCents,
        public int $optionsPerUnitCents,
        public int $quantity,
    ) {}

    public function unitTotalCents(): int
    {
        return $this->unitBaseCents + $this->optionsPerUnitCents;
    }

    public function totalCents(): int
    {
        return $this->unitTotalCents() * $this->quantity;
    }
}
