<?php

namespace App\Enums;

/**
 * How the chosen options of a group affect the item price.
 * Chosen by the merchant per option group (e.g. pizza flavors).
 */
enum PricingMode: string
{
    /** Every chosen option adds its price × quantity. */
    case Sum = 'sum';

    /** Only the most expensive chosen option is charged. */
    case Highest = 'highest';

    /** The average of the chosen options is charged (half-and-half pizza). */
    case Average = 'average';

    public function label(): string
    {
        return match ($this) {
            self::Sum => 'Somar opções',
            self::Highest => 'Cobrar a opção mais cara',
            self::Average => 'Cobrar a média das opções',
        };
    }
}
