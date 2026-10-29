<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Payments\OnlinePayments;

/**
 * An order paid online and cancelled by the store gets its money back through the provider.
 */
class RefundOnlinePayment
{
    public function __construct(private readonly OnlinePayments $payments) {}

    public function handle(OrderStatusChanged $event): void
    {
        if ($event->to === OrderStatus::Cancelled && $event->order->isPaidOnline() && $event->order->paid_at !== null) {
            $this->payments->refundOrder($event->order);
        }
    }
}
