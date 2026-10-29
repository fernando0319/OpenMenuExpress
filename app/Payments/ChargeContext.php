<?php

namespace App\Payments;

use Carbon\CarbonImmutable;

/**
 * Order data a provider needs to create a charge, prepared once for all of them.
 */
final readonly class ChargeContext
{
    public function __construct(
        public int $amountCents,
        public string $description,
        public string $notificationUrl,
        public string $returnUrl,
        public CarbonImmutable $expiresAt,
        public string $orderToken,
    ) {}

    /**
     * "50.50", as most APIs want the amount.
     */
    public function amount(): string
    {
        return number_format($this->amountCents / 100, 2, '.', '');
    }
}
