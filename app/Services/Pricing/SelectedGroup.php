<?php

namespace App\Services\Pricing;

use App\Enums\PricingMode;

final readonly class SelectedGroup
{
    /**
     * @param  list<SelectedOption>  $options
     */
    public function __construct(
        public PricingMode $mode,
        public array $options,
    ) {}

    /**
     * Amount this group adds to one unit of the item, in cents.
     */
    public function priceCents(): int
    {
        if ($this->options === []) {
            return 0;
        }

        return match ($this->mode) {
            PricingMode::Sum => array_sum(array_map(
                fn (SelectedOption $option) => $option->priceCents * $option->quantity,
                $this->options,
            )),
            PricingMode::Highest => max(array_map(
                fn (SelectedOption $option) => $option->priceCents,
                $this->options,
            )),
            PricingMode::Average => $this->averageCents(),
        };
    }

    /**
     * Weighted by quantity and rounded half up to the cent,
     * so 2× calabresa + 1× frango averages as three slices.
     */
    private function averageCents(): int
    {
        $total = 0;
        $count = 0;

        foreach ($this->options as $option) {
            $total += $option->priceCents * $option->quantity;
            $count += $option->quantity;
        }

        return intdiv(2 * $total + $count, 2 * $count);
    }
}
