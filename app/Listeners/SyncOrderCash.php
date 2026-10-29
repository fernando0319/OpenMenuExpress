<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Services\Cash\CashRegister;

/**
 * Keeps the cash session in step with the orders: a completed order paid on delivery
 * becomes a sale, a cancelled paid order becomes a refund.
 */
class SyncOrderCash
{
    public function __construct(private readonly CashRegister $register) {}

    public function handle(OrderStatusChanged $event): void
    {
        match ($event->to) {
            OrderStatus::Completed => $this->register->receiveDeclared($event->order, $event->by),
            OrderStatus::Cancelled => $this->register->refund($event->order, $event->by),
            default => null,
        };
    }
}
