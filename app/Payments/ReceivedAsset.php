<?php

namespace App\Payments;

use App\Enums\Asset;

/**
 * What actually reached the store for a paid charge, when it was not reais (e.g. satoshis).
 */
final readonly class ReceivedAsset
{
    public function __construct(
        public Asset $asset,
        public int $units,
    ) {}
}
