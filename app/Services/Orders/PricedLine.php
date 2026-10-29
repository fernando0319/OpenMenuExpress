<?php

namespace App\Services\Orders;

use App\Models\Product;
use App\Services\Pricing\ItemPrice;

/**
 * One cart line after the server checked and priced it.
 *
 * @phpstan-type ChosenOption array{option_id: int|null, group_name: string, option_name: string, quantity: int, price_cents: int}
 */
final readonly class PricedLine
{
    /**
     * @param  list<ChosenOption>  $options
     */
    public function __construct(
        public Product $product,
        public ItemPrice $price,
        public ?string $notes,
        public array $options,
    ) {}
}
