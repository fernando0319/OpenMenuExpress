<?php

namespace App\Services\Pricing;

use InvalidArgumentException;

/**
 * Computes the price of one order line on the server.
 * The browser may show a preview, but only this result is charged.
 */
final class ItemPriceCalculator
{
    /**
     * @param  list<SelectedGroup>  $groups
     */
    public function calculate(int $baseCents, int $quantity, array $groups = []): ItemPrice
    {
        if ($baseCents < 0) {
            throw new InvalidArgumentException('Base price cannot be negative.');
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $optionsCents = array_sum(array_map(
            fn (SelectedGroup $group) => $group->priceCents(),
            $groups,
        ));

        return new ItemPrice($baseCents, $optionsCents, $quantity);
    }
}
