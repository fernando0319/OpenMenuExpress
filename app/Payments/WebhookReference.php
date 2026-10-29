<?php

namespace App\Payments;

final readonly class WebhookReference
{
    public function __construct(
        public ?int $onlinePaymentId = null,
        public ?string $providerRef = null,
    ) {}
}
