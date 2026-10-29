<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Services\CourierNetwork\Dispatcher;

/**
 * A network run follows its order: cancelled with it, or counted as done once picked up.
 */
class SyncNetworkDelivery
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    public function handle(OrderStatusChanged $event): void
    {
        $this->dispatcher->followOrder($event->order, $event->to);
    }
}
