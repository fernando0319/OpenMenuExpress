<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case OutForDelivery = 'out_for_delivery';
    case ReadyForPickup = 'ready_for_pickup';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Aguardando pagamento',
            self::Pending => 'Pendente',
            self::Accepted => 'Aceito',
            self::Preparing => 'Em preparo',
            self::Ready => 'Pronto',
            self::OutForDelivery => 'Saiu para entrega',
            self::ReadyForPickup => 'Disponível para retirada',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
            self::Refunded => 'Reembolsado',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Refunded], true);
    }

    /**
     * Statuses the merchant can move an order to from this one. Steps may be skipped
     * (a small shop can go straight from accepted to completed), never undone.
     *
     * @return list<self>
     */
    public function nextFor(Fulfillment $fulfillment): array
    {
        $handOff = match ($fulfillment) {
            Fulfillment::Delivery => [self::OutForDelivery],
            Fulfillment::Pickup => [self::ReadyForPickup],
            Fulfillment::DineIn => [],
        };

        $next = match ($this) {
            self::PendingPayment, self::Pending => [self::Accepted],
            self::Accepted => [self::Preparing, self::Ready, ...$handOff, self::Completed],
            self::Preparing => [self::Ready, ...$handOff, self::Completed],
            self::Ready => [...$handOff, self::Completed],
            self::OutForDelivery, self::ReadyForPickup => [self::Completed],
            self::Completed, self::Cancelled, self::Refunded => [],
        };

        return $this->isFinal() ? [] : [...$next, self::Cancelled];
    }

    public function canMoveTo(self $target, Fulfillment $fulfillment): bool
    {
        return in_array($target, $this->nextFor($fulfillment), true);
    }
}
