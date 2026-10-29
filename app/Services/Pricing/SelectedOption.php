<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

final readonly class SelectedOption
{
    public function __construct(
        public int $priceCents,
        public int $quantity = 1,
    ) {
        if ($priceCents < 0) {
            throw new InvalidArgumentException('Option price cannot be negative.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Option quantity must be at least 1.');
        }
    }
}
