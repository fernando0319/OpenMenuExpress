<?php

namespace App\Enums;

/**
 * Price of a pizza with more than one flavor. With equal fractions (½ + ½, ⅓ + ⅓ + ⅓),
 * charging each fraction of its flavor is the same as the average.
 */
enum PizzaPricing: string
{
    case Highest = 'highest';
    case Average = 'average';

    public function label(): string
    {
        return match ($this) {
            self::Highest => 'Cobrar o sabor mais caro',
            self::Average => 'Cobrar a média (proporcional às frações)',
        };
    }

    /**
     * @param  list<int>  $flavorPrices
     */
    public function price(array $flavorPrices): int
    {
        if ($flavorPrices === []) {
            return 0;
        }

        return match ($this) {
            self::Highest => max($flavorPrices),
            self::Average => (int) round(array_sum($flavorPrices) / count($flavorPrices)),
        };
    }
}
