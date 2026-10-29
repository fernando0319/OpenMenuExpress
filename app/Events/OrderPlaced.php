<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A customer, the POS or a waiter created an order.
 * Messaging, printing and the live panel will listen to it.
 */
class OrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Order $order) {}
}
