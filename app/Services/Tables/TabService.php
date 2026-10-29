<?php

namespace App\Services\Tables;

use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\TabStatus;
use App\Events\OrderPlaced;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Tab;
use App\Models\User;
use App\Printing\PrintQueue;
use App\Services\Cash\CashRegister;
use App\Services\Cash\Payment;
use App\Services\Cash\Settlement;
use App\Services\Orders\CartPricer;
use App\Services\Orders\ChangeOrderStatus;
use App\Services\Orders\OrderRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Life of a table's tab: open, send rounds to the kitchen, ask for the bill, pay and free the table.
 *
 * @phpstan-import-type ItemInput from CartPricer
 */
final class TabService
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly OrderRecorder $recorder,
        private readonly CashRegister $register,
        private readonly ChangeOrderStatus $changeStatus,
        private readonly PrintQueue $printQueue,
    ) {}

    public function open(DiningTable $table, User $by, ?int $people = null, ?string $customerName = null): Tab
    {
        return DB::transaction(function () use ($table, $by, $people, $customerName): Tab {
            $table = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();

            if (! $table->is_active) {
                $this->fail('table', 'Esta mesa está desativada.');
            }

            if (! $table->isFree()) {
                $this->fail('table', "A {$table->name} já está ocupada.");
            }

            return $table->tabs()->create([
                'store_id' => $table->store_id,
                'opened_by' => $by->id,
                'status' => TabStatus::Open,
                'people' => $people,
                'customer_name' => $customerName,
                'opened_at' => now(),
            ]);
        });
    }

    /**
     * Sends a round of items to the kitchen as an order of this tab.
     *
     * @param  list<ItemInput>  $items
     */
    public function addRound(Tab $tab, array $items, User $by, ?string $notes = null): Order
    {
        $lines = $this->pricer->price($tab->store, $items);
        $subtotal = CartPricer::subtotal($lines);

        $order = DB::transaction(function () use ($tab, $lines, $subtotal, $by, $notes): Order {
            $tab = $this->lockActive($tab);
            $this->pricer->reserveStock($lines);

            // New items after the bill was asked for: the table is consuming again.
            if ($tab->status === TabStatus::Closing) {
                $tab->update(['status' => TabStatus::Open, 'bill_requested_at' => null]);
            }

            return $this->recorder->record($tab->store, [
                'tab_id' => $tab->id,
                'customer_name' => trim($tab->tablesLabel().($tab->customer_name !== null ? " · {$tab->customer_name}" : '')),
                'channel' => OrderChannel::Waiter,
                'fulfillment' => Fulfillment::DineIn,
                'subtotal_cents' => $subtotal,
                'discount_cents' => 0,
                'delivery_fee_cents' => 0,
                'total_cents' => $subtotal,
                'payment_method' => Order::PAYMENT_TAB,
                'notes' => $notes,
            ], $lines, OrderStatus::Accepted, $by);
        });

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * Marks the table as waiting for the bill and prints the check for the guests.
     *
     * @return bool Whether the check went to a printer.
     */
    public function requestBill(Tab $tab): bool
    {
        $tab = DB::transaction(function () use ($tab): Tab {
            $tab = $this->lockActive($tab);

            if ($tab->consumedCents() === 0) {
                $this->fail('tab', 'A mesa ainda não consumiu nada.');
            }

            $tab->update(['status' => TabStatus::Closing, 'bill_requested_at' => now()]);

            return $tab;
        });

        return $this->printQueue->queueTabBill($tab, TabBill::for($tab)) > 0;
    }

    public function transfer(Tab $tab, DiningTable $target): Tab
    {
        return DB::transaction(function () use ($tab, $target): Tab {
            $tab = $this->lockActive($tab);
            $target = DiningTable::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            if ($target->store_id !== $tab->store_id || ! $target->is_active) {
                $this->fail('table', 'Mesa de destino inválida.');
            }

            if (! $target->isFree()) {
                $this->fail('table', "A {$target->name} está ocupada.");
            }

            $tab->update(['dining_table_id' => $target->id]);

            return $tab;
        });
    }

    /**
     * Joins another table to this tab: one bill for a group spread over several tables.
     * If the other table already had its own tab, its rounds (and tables) move over to this one.
     */
    public function join(Tab $tab, DiningTable $other, User $by): Tab
    {
        return DB::transaction(function () use ($tab, $other, $by): Tab {
            $tab = $this->lockActive($tab);
            $other = DiningTable::query()->whereKey($other->id)->lockForUpdate()->firstOrFail();

            if ($other->store_id !== $tab->store_id || ! $other->is_active || $other->id === $tab->dining_table_id) {
                $this->fail('table', 'Escolha outra mesa para juntar.');
            }

            if ($other->joined_tab_id !== null) {
                $this->fail('table', $other->joined_tab_id === $tab->id
                    ? "A {$other->name} já está junta com esta mesa."
                    : "A {$other->name} já está junta com outra mesa. Separe antes.");
            }

            $otherTab = $other->activeTab()->lockForUpdate()->first();

            if ($otherTab !== null) {
                $movedCents = $otherTab->consumedCents();

                $otherTab->orders()->update(['tab_id' => $tab->id]);
                $otherTab->joinedTables()->update(['joined_tab_id' => $tab->id]);
                $otherTab->update(['status' => TabStatus::Cancelled, 'closed_by' => $by->id, 'closed_at' => now()]);

                $tab->update([
                    'people' => $tab->people !== null || $otherTab->people !== null ? (int) $tab->people + (int) $otherTab->people : null,
                    'customer_name' => $tab->customer_name ?? $otherTab->customer_name,
                    // The joined table brought consumption: the bill printed before is outdated.
                    ...($movedCents > 0 && $tab->status === TabStatus::Closing ? ['status' => TabStatus::Open, 'bill_requested_at' => null] : []),
                ]);
            }

            $other->forceFill(['joined_tab_id' => $tab->id])->save();

            return $tab;
        });
    }

    /**
     * Frees a joined table again. What was consumed stays on the tab.
     */
    public function separate(Tab $tab, DiningTable $table): void
    {
        DB::transaction(function () use ($tab, $table): void {
            $tab = $this->lockActive($tab);
            $table = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();

            if ($table->joined_tab_id !== $tab->id) {
                $this->fail('table', "A {$table->name} não está junta com esta mesa.");
            }

            $table->forceFill(['joined_tab_id' => null])->save();
        });
    }

    /**
     * Receives the bill in the cash register and frees the table. The rounds become completed.
     *
     * @param  list<Payment>  $payments
     */
    public function close(Tab $tab, array $payments, bool $withServiceFee, int $discountCents, User $by): Tab
    {
        $tab = DB::transaction(function () use ($tab, $payments, $withServiceFee, $discountCents, $by): Tab {
            $tab = $this->lockActive($tab);
            $bill = TabBill::for($tab, $withServiceFee, $discountCents);

            if ($bill->subtotalCents === 0) {
                $this->fail('tab', 'A mesa não consumiu nada. Use "Liberar mesa".');
            }

            $settlement = Settlement::of($bill->totalCents(), $payments);
            $this->register->receiveTab($tab, $settlement, $by);

            $tab->update([
                'status' => TabStatus::Closed,
                'closed_by' => $by->id,
                'closed_at' => now(),
                'subtotal_cents' => $bill->subtotalCents,
                'service_fee_cents' => $bill->serviceFeeCents,
                'discount_cents' => $bill->discountCents,
                'total_cents' => $bill->totalCents(),
                'payment_method' => count($settlement->payments) === 1 ? $settlement->payments[0]->method->value : Order::PAYMENT_SPLIT,
            ]);

            $tab->orders()->where('status', '!=', OrderStatus::Cancelled)->update(['paid_at' => now()]);
            $tab->joinedTables()->update(['joined_tab_id' => null]);

            return $tab;
        });

        // Outside the transaction: each change fires its own events (kitchen screens, messages).
        foreach ($tab->orders()->get() as $order) {
            if (! $order->status->isFinal()) {
                $this->changeStatus->handle($order, OrderStatus::Completed, $by);
            }
        }

        return $tab;
    }

    /**
     * Frees a table that consumed nothing (guests left, opened by mistake).
     */
    public function release(Tab $tab, User $by): void
    {
        DB::transaction(function () use ($tab, $by): void {
            $tab = $this->lockActive($tab);

            if ($tab->consumedCents() > 0) {
                $this->fail('tab', 'A mesa tem consumo. Feche a conta ou cancele as rodadas antes.');
            }

            $tab->update(['status' => TabStatus::Cancelled, 'closed_by' => $by->id, 'closed_at' => now()]);
            $tab->joinedTables()->update(['joined_tab_id' => null]);
        });
    }

    private function lockActive(Tab $tab): Tab
    {
        $locked = Tab::query()->whereKey($tab->id)->lockForUpdate()->firstOrFail();

        if (! $locked->status->isActive()) {
            $this->fail('tab', 'Esta conta já foi fechada.');
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
