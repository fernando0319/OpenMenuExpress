<?php

namespace App\Payments;

use App\Enums\Asset;
use Carbon\CarbonImmutable;

/**
 * What the provider created for the customer to pay.
 */
final readonly class Charge
{
    public function __construct(
        public string $providerRef,
        public ?string $pixCode = null,
        public ?string $checkoutUrl = null,
        public ?CarbonImmutable $expiresAt = null,
        // A Lightning invoice (BOLT11), priced in satoshis at a locked rate.
        public ?string $paymentRequest = null,
        public ?Asset $asset = null,
        public ?int $assetUnits = null,
        public ?string $rate = null,
    ) {}
}
