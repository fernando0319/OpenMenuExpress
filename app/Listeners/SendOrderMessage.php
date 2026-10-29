<?php

namespace App\Listeners;

use App\Contracts\MessagingProvider;
use App\Enums\MessageEvent;
use App\Enums\Module;
use App\Enums\OrderStatus;
use App\Events\OrderEdited;
use App\Events\OrderPlaced;
use App\Events\OrderStatusChanged;
use App\Messaging\MessagingException;
use App\Messaging\OrderMessageComposer;
use App\Models\MessageLog;
use App\Models\Order;
use App\Services\Billing\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Sends the customer the WhatsApp message configured for each moment of the order.
 * Runs on the queue, so the panel and the checkout never wait for the provider.
 */
class SendOrderMessage implements ShouldQueue
{
    public function __construct(
        private readonly MessagingProvider $provider,
        private readonly OrderMessageComposer $composer,
    ) {}

    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $this->send($event->order, MessageEvent::OrderPlaced);
    }

    /**
     * Only when whoever edited chose to tell the customer.
     */
    public function handleOrderEdited(OrderEdited $event): void
    {
        if ($event->notifyCustomer) {
            $this->send($event->order, MessageEvent::OrderUpdated);
        }
    }

    public function handleOrderStatusChanged(OrderStatusChanged $event): void
    {
        // An online payment that never happened: for the customer the order was never placed.
        if ($event->from === OrderStatus::PendingPayment) {
            return;
        }

        $messageEvent = MessageEvent::forStatus($event->to);

        if ($messageEvent !== null) {
            $this->send($event->order, $messageEvent);
        }
    }

    private function send(Order $order, MessageEvent $event): void
    {
        $store = $order->store;

        if (! $store->whatsapp_enabled || $order->customer_phone === null || ! Subscription::for($store)->hasModule(Module::WhatsApp)) {
            return;
        }

        $template = $this->composer->templateFor($store, $event);

        if ($template === null) {
            return;
        }

        $log = $store->messageLogs()->create([
            'order_id' => $order->id,
            'event' => $event,
            'recipient' => $order->customer_phone,
            'body' => $this->composer->render($template, $order),
            'status' => MessageLog::STATUS_PENDING,
        ]);

        try {
            $this->provider->sendText($store, $log->recipient, $log->body);
            $log->update(['status' => MessageLog::STATUS_SENT, 'sent_at' => now()]);
        } catch (MessagingException $exception) {
            $log->update(['status' => MessageLog::STATUS_FAILED, 'error' => mb_substr($exception->getMessage(), 0, 250)]);
        }
    }
}
