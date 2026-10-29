<?php

namespace App\Payments;

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
    ) {}
}
