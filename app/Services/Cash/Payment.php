<?php

namespace App\Services\Cash;

use App\Enums\PaymentMethod;

final readonly class Payment
{
    public function __construct(
        public PaymentMethod $method,
        public int $amountCents,
    ) {}
}
