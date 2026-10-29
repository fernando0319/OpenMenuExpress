<?php

namespace App\Services\Cash;

use App\Enums\CashMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Order;
use App\Models\Store;
use App\Models\Tab;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens and closes the store's cash session and records every money movement in it.
 * One session per store at a time, shared by everyone at the counter.
 */
final class CashRegister
{
    public function current(Store $store): ?CashSession
    {
        return $store->cashSessions()->open()->latest('id')->first();
    }

    public function open(Store $store, User $by, int $openingCents): CashSession
    {
        return DB::transaction(function () use ($store, $by, $openingCents): CashSession {
            Store::query()->whereKey($store->id)->lockForUpdate()->first();

            if ($this->current($store) !== null) {
                $this->fail('cash', 'O caixa já está aberto.');
            }

            return $store->cashSessions()->create([
                'opened_by' => $by->id,
                'opening_cents' => $openingCents,
                'opened_at' => now(),
            ]);
        });
    }

    /**
     * Supply (money put in the drawer) or withdrawal (money taken out).
     */
    public function move(CashSession $session, CashMovementType $type, int $amountCents, ?string $description, User $by): CashMovement
    {
        if (! in_array($type, [CashMovementType::Supply, CashMovementType::Withdrawal], true)) {
            $this->fail('type', 'Movimento inválido.');
        }

        return DB::transaction(function () use ($session, $type, $amountCents, $description, $by): CashMovement {
            $session = $this->lockOpen($session);

            if ($type === CashMovementType::Withdrawal) {
                $available = CashSummary::of($session)->expectedCashCents();

                if ($amountCents > $available) {
                    $this->fail('amount', 'A sangria é maior que o dinheiro em caixa (R$ '.Money::toInput(max(0, $available)).').');
                }
            }

            return $session->movements()->create([
                'store_id' => $session->store_id,
                'user_id' => $by->id,
                'type' => $type,
                'payment_method' => PaymentMethod::Cash,
                'amount_cents' => $amountCents,
                'description' => $description,
            ]);
        });
    }

    public function close(CashSession $session, int $countedCashCents, ?string $notes, User $by): CashSession
    {
        return DB::transaction(function () use ($session, $countedCashCents, $notes, $by): CashSession {
            $session = $this->lockOpen($session);

            $session->update([
                'closed_by' => $by->id,
                'closed_at' => now(),
                'expected_cash_cents' => CashSummary::of($session)->expectedCashCents(),
                'counted_cash_cents' => $countedCashCents,
                'notes' => $notes,
            ]);

            return $session;
        });
    }

    /**
     * Records the order's payment in the open session. Call inside the order transaction when
     * the order is being created, or on its own for an order paid later.
     */
    public function receive(Order $order, Settlement $settlement, ?User $by): void
    {
        DB::transaction(function () use ($order, $settlement, $by): void {
            $session = $this->current($order->store);

            if ($session === null) {
                $this->fail('cash', 'Abra o caixa para registrar pagamentos.');
            }

            $session = $this->lockOpen($session);

            $current = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($current->paid_at !== null) {
                $this->fail('payments', 'Este pedido já foi pago.');
            }

            if ($current->status === OrderStatus::Cancelled) {
                $this->fail('payments', 'Pedido cancelado não pode receber pagamento.');
            }

            foreach ($settlement->payments as $payment) {
                $session->movements()->create([
                    'store_id' => $session->store_id,
                    'order_id' => $current->id,
                    'user_id' => $by?->id,
                    'type' => CashMovementType::Sale,
                    'payment_method' => $payment->method,
                    'amount_cents' => $payment->amountCents,
                    'description' => "Pedido nº {$current->number}",
                ]);
            }

            $current->update([
                'paid_at' => now(),
                'payment_method' => count($settlement->payments) === 1 ? $settlement->payments[0]->method->value : Order::PAYMENT_SPLIT,
                'change_for_cents' => $settlement->changeCents > 0 ? $settlement->cashTenderedCents : null,
            ]);

            $order->setRawAttributes($current->getAttributes(), true);
        });
    }

    /**
     * Records a table's bill in the open session. Call inside the tab closing transaction.
     */
    public function receiveTab(Tab $tab, Settlement $settlement, ?User $by): void
    {
        $session = $this->current($tab->store);

        if ($session === null) {
            $this->fail('cash', 'Abra o caixa para fechar a conta.');
        }

        $session = $this->lockOpen($session);

        foreach ($settlement->payments as $payment) {
            $session->movements()->create([
                'store_id' => $session->store_id,
                'tab_id' => $tab->id,
                'user_id' => $by?->id,
                'type' => CashMovementType::Sale,
                'payment_method' => $payment->method,
                'amount_cents' => $payment->amountCents,
                'description' => "Conta da {$tab->tablesLabel()}",
            ]);
        }
    }

    /**
     * When an order paid on delivery is completed, takes the declared method as paid.
     * Without an open session nothing is recorded; it can be received by hand later.
     */
    public function receiveDeclared(Order $order, ?User $by): bool
    {
        $method = PaymentMethod::tryFrom($order->payment_method);

        if ($order->paid_at !== null || $method === null || $this->current($order->store) === null) {
            return false;
        }

        $due = $this->dueCents($order);

        if ($due <= 0) {
            return false;
        }

        $this->receive($order, Settlement::of($due, [new Payment($method, $due)]), $by);

        return true;
    }

    /**
     * What came in for the order, net of refunds.
     */
    public function receivedCents(Order $order): int
    {
        $net = 0;

        foreach ($order->cashMovements()->whereIn('type', [CashMovementType::Sale, CashMovementType::Refund])->get() as $movement) {
            $net += $movement->signedCents();
        }

        return $net;
    }

    /**
     * What is still to be received: the whole total, or the difference after an edit raised it.
     */
    public function dueCents(Order $order): int
    {
        return max(0, $order->total_cents - $this->receivedCents($order));
    }

    /**
     * Gives back part of what was received (an edit lowered the total): cash first, then the
     * other methods the customer used.
     *
     * @return bool False when no session is open to record it.
     */
    public function refundPart(Order $order, int $cents, ?User $by): bool
    {
        $session = $this->current($order->store);

        if ($session === null || $cents <= 0) {
            return false;
        }

        $net = [];

        foreach ($order->cashMovements()->whereIn('type', [CashMovementType::Sale, CashMovementType::Refund])->get() as $movement) {
            if ($movement->payment_method !== null) {
                $key = $movement->payment_method->value;
                $net[$key] = ($net[$key] ?? 0) + $movement->signedCents();
            }
        }

        uksort($net, fn (string $a, string $b) => ($b === PaymentMethod::Cash->value) <=> ($a === PaymentMethod::Cash->value));
        $left = $cents;

        foreach ($net as $method => $available) {
            $amount = min($left, max(0, $available));

            if ($amount === 0) {
                continue;
            }

            $session->movements()->create([
                'store_id' => $session->store_id,
                'order_id' => $order->id,
                'user_id' => $by?->id,
                'type' => CashMovementType::Refund,
                'payment_method' => $method,
                'amount_cents' => $amount,
                'description' => "Alteração do pedido nº {$order->number}",
            ]);
            $left -= $amount;

            if ($left === 0) {
                break;
            }
        }

        return true;
    }

    /**
     * Gives back what a cancelled order had paid, as refunds in the open session.
     *
     * @return bool False when there was nothing to refund or no session open to record it.
     */
    public function refund(Order $order, ?User $by): bool
    {
        return DB::transaction(function () use ($order, $by): bool {
            $net = [];

            foreach ($order->cashMovements()->lockForUpdate()->get() as $movement) {
                if ($movement->payment_method !== null && in_array($movement->type, [CashMovementType::Sale, CashMovementType::Refund], true)) {
                    $key = $movement->payment_method->value;
                    $net[$key] = ($net[$key] ?? 0) + $movement->signedCents();
                }
            }

            $net = array_filter($net, fn (int $cents) => $cents > 0);
            $session = $this->current($order->store);

            if ($net === [] || $session === null) {
                return false;
            }

            foreach ($net as $method => $cents) {
                $session->movements()->create([
                    'store_id' => $session->store_id,
                    'order_id' => $order->id,
                    'user_id' => $by?->id,
                    'type' => CashMovementType::Refund,
                    'payment_method' => $method,
                    'amount_cents' => $cents,
                    'description' => "Cancelamento do pedido nº {$order->number}",
                ]);
            }

            return true;
        });
    }

    private function lockOpen(CashSession $session): CashSession
    {
        $locked = CashSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isOpen()) {
            $this->fail('cash', 'Este caixa já foi fechado.');
        }

        return $locked;
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
