<?php

namespace App\Listeners;

use App\Enums\AutoPrint;
use App\Enums\OrderStatus;
use App\Events\OrderEdited;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Printing\PrintQueue;

/**
 * Queues the ticket automatically at the moment the store chose (order placed or accepted).
 * Only writes rows to print_jobs, so it runs inline; the agent picks them up.
 */
class PrintOrderTicket
{
    public function __construct(private readonly PrintQueue $queue) {}

    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $autoPrint = $event->order->store->auto_print;

        // PDV orders are born already accepted, so they print under either setting.
        $prints = $autoPrint === AutoPrint::WhenPlaced
            || ($autoPrint === AutoPrint::WhenAccepted && $event->order->status !== OrderStatus::Pending);

        if ($prints) {
            $this->queue->queueOrder($event->order);
        }
    }

    /**
     * After an edit, the kitchen gets only the difference, if it already had the order.
     */
    public function handleOrderEdited(OrderEdited $event): void
    {
        $autoPrint = $event->order->store->auto_print;
        $kitchenHasIt = $autoPrint === AutoPrint::WhenPlaced
            || ($autoPrint === AutoPrint::WhenAccepted && ! in_array($event->order->status, [OrderStatus::Pending, OrderStatus::PendingPayment], true));

        if ($kitchenHasIt && $event->change->touchesItems()) {
            $this->queue->queueOrderChange($event->order, $event->change);
        }
    }

    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        if ($event->to === OrderStatus::Accepted && $event->order->store->auto_print === AutoPrint::WhenAccepted) {
            $this->queue->queueOrder($event->order);
        }
    }
}
