<?php

namespace App\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;

/**
 * Totals of a cash session, computed from its movements.
 */
final readonly class CashSummary
{
    /**
     * @param  array<string, int>  $netByMethod  Sales minus refunds, per payment method value.
     */
    public function __construct(
        public int $openingCents,
        public array $netByMethod,
        public int $salesCents,
        public int $refundsCents,
        public int $suppliesCents,
        public int $withdrawalsCents,
        public int $ordersCount,
    ) {}

    public static function of(CashSession $session): self
    {
        $movements = $session->movements()->get(['type', 'payment_method', 'amount_cents', 'order_id', 'tab_id']);
        $sales = $movements->where('type', CashMovementType::Sale);

        $net = [];
        $totals = array_fill_keys(array_column(CashMovementType::cases(), 'value'), 0);

        foreach ($movements as $movement) {
            /** @var CashMovement $movement */
            $totals[$movement->type->value] += $movement->amount_cents;

            if ($movement->payment_method !== null && in_array($movement->type, [CashMovementType::Sale, CashMovementType::Refund], true)) {
                $key = $movement->payment_method->value;
                $net[$key] = ($net[$key] ?? 0) + $movement->signedCents();
            }
        }

        return new self(
            $session->opening_cents,
            $net,
            $totals[CashMovementType::Sale->value],
            $totals[CashMovementType::Refund->value],
            $totals[CashMovementType::Supply->value],
            $totals[CashMovementType::Withdrawal->value],
            // A table's bill counts as one sale, like an order.
            $sales->pluck('order_id')->filter()->unique()->count() + $sales->pluck('tab_id')->filter()->unique()->count(),
        );
    }

    /**
     * Cash that should be in the drawer: opening + cash sales − cash refunds + supplies − withdrawals.
     */
    public function expectedCashCents(): int
    {
        return $this->openingCents
            + ($this->netByMethod[PaymentMethod::Cash->value] ?? 0)
            + $this->suppliesCents
            - $this->withdrawalsCents;
    }

    /**
     * @return list<array{method: string, label: string, cents: int}>
     */
    public function byMethod(): array
    {
        $rows = [];

        foreach (PaymentMethod::cases() as $method) {
            $rows[] = [
                'method' => $method->value,
                'label' => $method->label(),
                'cents' => $this->netByMethod[$method->value] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'openingCents' => $this->openingCents,
            'byMethod' => $this->byMethod(),
            'salesCents' => $this->salesCents,
            'refundsCents' => $this->refundsCents,
            'suppliesCents' => $this->suppliesCents,
            'withdrawalsCents' => $this->withdrawalsCents,
            'netSalesCents' => $this->salesCents - $this->refundsCents,
            'ordersCount' => $this->ordersCount,
            'expectedCashCents' => $this->expectedCashCents(),
        ];
    }
}
