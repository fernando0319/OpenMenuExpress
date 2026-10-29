<?php

namespace App\Services\Finance;

use App\Enums\FinanceFrequency;
use App\Enums\FinancePaymentMethod;
use App\Models\FinanceCategory;
use App\Models\FinanceInstallment;
use App\Models\FinanceTitle;
use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * The shapes the finance pages receive.
 */
final class FinancePresenter
{
    public static function today(Store $store): CarbonImmutable
    {
        return CarbonImmutable::now($store->timezone)->startOfDay();
    }

    /**
     * Expects the installment with its title (and the title's supplier and category).
     *
     * @return array<string, mixed>
     */
    public static function installment(FinanceInstallment $installment, CarbonImmutable $today): array
    {
        $title = $installment->title;
        $status = $installment->status($today);

        return [
            'id' => $installment->id,
            'titleId' => $title->id,
            'type' => $title->type->value,
            'description' => $title->description,
            'number' => $installment->number,
            'count' => $title->installments_count,
            'party' => $title->partyName(),
            'category' => $title->category?->name,
            'documentNumber' => $title->document_number,
            'dueOn' => $installment->due_on->format('Y-m-d'),
            'amountCents' => $installment->amount_cents,
            'paidOn' => $installment->paid_on?->format('Y-m-d'),
            'paidCents' => $installment->paid_cents,
            'paymentMethod' => $installment->payment_method?->label(),
            'automatic' => $installment->settled_automatically,
            'throughCash' => $installment->cash_movement_id !== null,
            'notes' => $installment->notes,
            'status' => $status->value,
            'statusLabel' => $status->label($title->type),
            'daysLate' => $status->value === 'overdue' ? $installment->daysLate($today) : 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function title(FinanceTitle $title): array
    {
        return [
            'id' => $title->id,
            'type' => $title->type->value,
            'typeLabel' => $title->type->label(),
            'description' => $title->description,
            'supplierId' => $title->supplier_id,
            'party' => $title->partyName(),
            'counterparty' => $title->counterparty,
            'category' => $title->category?->name,
            'categoryId' => $title->category_id,
            'documentNumber' => $title->document_number,
            'issuedOn' => $title->issued_on->format('Y-m-d'),
            'totalCents' => $title->total_cents,
            'installmentsCount' => $title->installments_count,
            'frequency' => $title->frequency->value,
            'frequencyLabel' => $title->frequency->label(),
            'autoSettle' => $title->auto_settle,
            'autoSettleMethod' => $title->auto_settle_method?->value,
            'autoSettleMethodLabel' => $title->auto_settle_method?->label(),
            'notes' => $title->notes,
            'canceled' => $title->canceled_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formOptions(Store $store, string $type): array
    {
        return [
            'suppliers' => $store->suppliers()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($supplier) => ['id' => $supplier->id, 'name' => $supplier->name])->values(),
            'categories' => $store->financeCategories()->where('type', $type)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (FinanceCategory $category) => ['id' => $category->id, 'name' => $category->name])->values(),
            'frequencies' => array_map(fn (FinanceFrequency $frequency) => ['value' => $frequency->value, 'label' => $frequency->label()], FinanceFrequency::cases()),
            'paymentMethods' => FinancePaymentMethod::options(),
        ];
    }
}
