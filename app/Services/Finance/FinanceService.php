<?php

namespace App\Services\Finance;

use App\Enums\CashMovementType;
use App\Enums\FinancePaymentMethod;
use App\Enums\FinanceType;
use App\Models\FinanceInstallment;
use App\Models\FinanceTitle;
use App\Models\Store;
use App\Models\User;
use App\Services\Cash\CashRegister;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payables and receivables: creating titles with their installments, settling ("baixa"),
 * undoing a settlement, canceling, and the automatic settlement on the due date.
 */
final class FinanceService
{
    public function __construct(private readonly CashRegister $cash) {}

    /**
     * Creates the default categories on the store's first visit to the module.
     */
    public function ensureCategories(Store $store): void
    {
        if ($store->financeCategories()->exists()) {
            return;
        }

        foreach (FinanceType::cases() as $type) {
            foreach ($type->defaultCategories() as $name) {
                $store->financeCategories()->create(['type' => $type, 'name' => $name]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $attributes  title columns
     * @param  list<array{due_on: string, amount_cents: int}>  $installments
     */
    public function create(Store $store, array $attributes, array $installments, User $by): FinanceTitle
    {
        return DB::transaction(function () use ($store, $attributes, $installments, $by): FinanceTitle {
            $title = $store->financeTitles()->create([
                ...$attributes,
                'installments_count' => count($installments),
                'created_by' => $by->id,
            ]);

            $this->writeInstallments($title, $installments);

            return $title;
        });
    }

    /**
     * Before anything is settled, everything can change. After that, only the descriptive fields:
     * amounts and dates of what was already paid are history.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{due_on: string, amount_cents: int}>  $installments
     */
    public function update(FinanceTitle $title, array $attributes, array $installments): void
    {
        DB::transaction(function () use ($title, $attributes, $installments): void {
            $title = FinanceTitle::query()->whereKey($title->id)->lockForUpdate()->firstOrFail();

            if ($title->installments()->whereNotNull('paid_on')->exists()) {
                $title->update(array_intersect_key($attributes, array_flip([
                    'description', 'supplier_id', 'counterparty', 'category_id', 'document_number', 'notes', 'auto_settle', 'auto_settle_method',
                ])));

                return;
            }

            $title->update([...$attributes, 'installments_count' => count($installments)]);
            $title->installments()->delete();
            $this->writeInstallments($title, $installments);
        });
    }

    /**
     * Records the payment (payable) or receipt (receivable) of an installment.
     * With $throughCash, the money also leaves (or enters) the open cash register.
     */
    public function settle(
        FinanceInstallment $installment,
        CarbonImmutable $paidOn,
        int $paidCents,
        FinancePaymentMethod $method,
        ?string $notes,
        bool $throughCash,
        ?User $by,
    ): void {
        DB::transaction(function () use ($installment, $paidOn, $paidCents, $method, $notes, $throughCash, $by): void {
            $installment = FinanceInstallment::query()->with('title')->whereKey($installment->id)->lockForUpdate()->firstOrFail();
            $title = $installment->title;

            if ($installment->paid_on !== null) {
                $this->fail('installment', 'Esta parcela já foi baixada.');
            }

            if ($title->canceled_at !== null) {
                $this->fail('installment', 'Este lançamento foi cancelado.');
            }

            $movementId = null;

            if ($throughCash) {
                if ($method !== FinancePaymentMethod::Cash || $by === null) {
                    $this->fail('through_cash', 'Só pagamentos em dinheiro passam pelo caixa.');
                }

                $session = $this->cash->current($title->store()->firstOrFail());

                if ($session === null) {
                    $this->fail('through_cash', 'Abra o caixa para lançar este valor nele, ou desmarque a opção.');
                }

                $movementId = $this->cash->move(
                    $session,
                    $title->type === FinanceType::Payable ? CashMovementType::Withdrawal : CashMovementType::Supply,
                    $paidCents,
                    $this->cashDescription($installment),
                    $by,
                )->id;
            }

            $installment->update([
                'paid_on' => $paidOn->format('Y-m-d'),
                'paid_cents' => $paidCents,
                'payment_method' => $method,
                'notes' => $notes,
                'settled_automatically' => $by === null,
                'settled_by' => $by?->id,
                'cash_movement_id' => $movementId,
            ]);
        });
    }

    /**
     * Undoes a settlement. Money that went through a still open cash register goes back the
     * other way; once that register is closed, the merchant adjusts it by hand.
     *
     * @return bool whether the cash register was adjusted too
     */
    public function undo(FinanceInstallment $installment, User $by): bool
    {
        return DB::transaction(function () use ($installment, $by): bool {
            $installment = FinanceInstallment::query()->with(['title', 'cashMovement.session'])->whereKey($installment->id)->lockForUpdate()->firstOrFail();

            if ($installment->paid_on === null) {
                $this->fail('installment', 'Esta parcela não está baixada.');
            }

            $adjusted = false;
            $movement = $installment->cashMovement;

            if ($movement !== null && $movement->session !== null && $movement->session->closed_at === null) {
                $this->cash->move(
                    $movement->session,
                    $movement->type === CashMovementType::Withdrawal ? CashMovementType::Supply : CashMovementType::Withdrawal,
                    $movement->amount_cents,
                    'Estorno: '.$this->cashDescription($installment),
                    $by,
                );
                $adjusted = true;
            }

            $installment->update([
                'paid_on' => null,
                'paid_cents' => null,
                'payment_method' => null,
                'settled_automatically' => false,
                'settled_by' => null,
                'cash_movement_id' => null,
            ]);

            return $adjusted;
        });
    }

    /**
     * Cancels what is still open; settled installments stay as history.
     */
    public function cancel(FinanceTitle $title): void
    {
        $title->update(['canceled_at' => now(), 'auto_settle' => false]);
    }

    public function reopen(FinanceTitle $title): void
    {
        $title->update(['canceled_at' => null]);
    }

    /**
     * Settles, on their due date, the installments of titles marked as automatic
     * (direct debit, card on file). Runs hourly, so each store gets its own local day.
     */
    public function autoSettleDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        FinanceInstallment::query()
            ->with('title.store')
            ->whereNull('paid_on')
            ->whereHas('title', fn ($title) => $title->where('auto_settle', true)->whereNull('canceled_at'))
            ->where('due_on', '<=', $now->addDay()->format('Y-m-d'))
            ->orderBy('id')
            ->chunkById(200, function ($installments) use ($now, &$count): void {
                foreach ($installments as $installment) {
                    $title = $installment->title;
                    $today = $now->setTimezone($title->store->timezone)->startOfDay();

                    if ($installment->due_on->format('Y-m-d') > $today->format('Y-m-d')) {
                        continue;
                    }

                    $this->settle(
                        $installment,
                        $installment->due_on,
                        $installment->amount_cents,
                        $title->auto_settle_method ?? FinancePaymentMethod::AutoDebit,
                        null,
                        false,
                        null,
                    );
                    $count++;
                }
            });

        return $count;
    }

    /**
     * @param  list<array{due_on: string, amount_cents: int}>  $installments
     */
    private function writeInstallments(FinanceTitle $title, array $installments): void
    {
        foreach ($installments as $index => $installment) {
            $title->installments()->create([
                'store_id' => $title->store_id,
                'number' => $index + 1,
                'due_on' => $installment['due_on'],
                'amount_cents' => $installment['amount_cents'],
            ]);
        }
    }

    private function cashDescription(FinanceInstallment $installment): string
    {
        $title = $installment->title;
        $suffix = $title->installments_count > 1 ? " ({$installment->number}/{$title->installments_count})" : '';

        return mb_substr(($title->type === FinanceType::Payable ? 'Conta paga: ' : 'Conta recebida: ').$title->description.$suffix, 0, 255);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
