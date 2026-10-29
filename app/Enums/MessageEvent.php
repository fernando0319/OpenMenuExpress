<?php

namespace App\Enums;

/**
 * Moments that can send an automatic message to the customer.
 * Each one has a default text the merchant can edit or turn off.
 */
enum MessageEvent: string
{
    case OrderPlaced = 'order_placed';
    case OrderAccepted = 'order_accepted';
    case OrderPreparing = 'order_preparing';
    case OrderReady = 'order_ready';
    case OrderOutForDelivery = 'order_out_for_delivery';
    case OrderReadyForPickup = 'order_ready_for_pickup';
    case OrderCompleted = 'order_completed';
    case OrderCancelled = 'order_cancelled';
    case OrderUpdated = 'order_updated';

    public static function forStatus(OrderStatus $status): ?self
    {
        return match ($status) {
            OrderStatus::Accepted => self::OrderAccepted,
            OrderStatus::Preparing => self::OrderPreparing,
            OrderStatus::Ready => self::OrderReady,
            OrderStatus::OutForDelivery => self::OrderOutForDelivery,
            OrderStatus::ReadyForPickup => self::OrderReadyForPickup,
            OrderStatus::Completed => self::OrderCompleted,
            OrderStatus::Cancelled => self::OrderCancelled,
            default => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::OrderPlaced => 'Pedido recebido',
            self::OrderAccepted => 'Pedido aceito',
            self::OrderPreparing => 'Em preparo',
            self::OrderReady => 'Pronto',
            self::OrderOutForDelivery => 'Saiu para entrega',
            self::OrderReadyForPickup => 'Pronto para retirada',
            self::OrderCompleted => 'Concluído',
            self::OrderCancelled => 'Cancelado',
            self::OrderUpdated => 'Pedido alterado',
        };
    }

    public function defaultBody(): string
    {
        return match ($this) {
            self::OrderPlaced => "Olá, {nome}! Recebemos seu pedido nº {pedido} na {loja}.\n\n{itens}\n\n{valores}\nPagamento: {pagamento}\n\n{entrega_detalhes}\n\nAcompanhe: {link_pedido}",
            self::OrderAccepted => 'Oba, {nome}! Seu pedido nº {pedido} foi aceito e já vai para o preparo.',
            self::OrderPreparing => 'Seu pedido nº {pedido} está sendo preparado.',
            self::OrderReady => 'Seu pedido nº {pedido} está pronto!',
            self::OrderOutForDelivery => "Seu pedido nº {pedido} saiu para entrega. Já já chega aí!\n\n{entrega_detalhes}\nTotal: {total} ({pagamento})",
            self::OrderReadyForPickup => 'Seu pedido nº {pedido} está pronto para retirada na {loja}.',
            self::OrderCompleted => 'Pedido nº {pedido} concluído. Obrigado pela preferência, {nome}!',
            self::OrderCancelled => 'Seu pedido nº {pedido} foi cancelado. Se tiver dúvidas, fale com a {loja}.',
            self::OrderUpdated => "Olá, {nome}! Seu pedido nº {pedido} foi atualizado.\n\n{itens}\n\n{valores}\n\nAcompanhe: {link_pedido}",
        };
    }

    /**
     * Messages that start enabled for a new store.
     */
    public function enabledByDefault(): bool
    {
        return $this !== self::OrderPreparing && $this !== self::OrderReady;
    }
}
