<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderChange;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Someone on the team changed an order after it was placed.
 * The kitchen gets a ticket with the difference; the customer may get a message.
 */
class OrderEdited
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderChange $change,
        public readonly bool $notifyCustomer,
    ) {}
}
