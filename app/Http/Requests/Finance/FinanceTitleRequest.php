<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinanceFrequency;
use App\Enums\FinancePaymentMethod;
use App\Enums\FinanceType;
use App\Models\FinanceTitle;
use App\Services\Finance\InstallmentPlan;
use App\Support\CurrentStore;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A payable or receivable with its installments, as edited on the form:
 * the form proposes the split and the person may adjust each date and amount.
 */
class FinanceTitleRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $installments = $this->input('installments');

        $this->merge([
            'total' => $this->money($this->input('total')),
            'supplier_id' => $this->input('supplier_id') ?: null,
            'category_id' => $this->input('category_id') ?: null,
            'auto_settle_method' => $this->boolean('auto_settle') ? ($this->input('auto_settle_method') ?: null) : null,
            'installments' => is_array($installments) ? array_values(array_map(fn ($row) => [
                'due_on' => is_array($row) ? ($row['due_on'] ?? null) : null,
                'amount_cents' => is_array($row) ? $this->money($row['amount'] ?? null) : null,
            ], $installments)) : $installments,
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $storeId = app(CurrentStore::class)->require()->id;

        return [
            'type' => [$this->title() === null ? 'required' : 'nullable', Rule::enum(FinanceType::class)],
            'description' => ['required', 'string', 'max:160'],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('store_id', $storeId)],
            'counterparty' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', Rule::exists('finance_categories', 'id')->where('store_id', $storeId)->where('type', $this->type()->value)],
            'document_number' => ['nullable', 'string', 'max:40'],
            'issued_on' => ['required', 'date_format:Y-m-d'],
            'total' => ['required', 'integer', 'min:1'],
            'frequency' => ['required', Rule::enum(FinanceFrequency::class)],
            'installments' => ['required', 'array', 'min:1', 'max:'.InstallmentPlan::MAX_INSTALLMENTS],
            'installments.*.due_on' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount_cents' => ['required', 'integer', 'min:1'],
            'auto_settle' => ['boolean'],
            'auto_settle_method' => ['nullable', 'required_if_accepted:auto_settle', Rule::enum(FinancePaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.required' => 'Descreva o lançamento (ex.: Compra de carnes).',
            'issued_on.required' => 'Informe a data.',
            'total.required' => 'Informe o valor total.',
            'total.integer' => 'Valor inválido. Use o formato 1500,00.',
            'total.min' => 'O valor precisa ser maior que zero.',
            'installments.required' => 'Informe ao menos uma parcela.',
            'installments.*.due_on.required' => 'Informe o vencimento de cada parcela.',
            'installments.*.due_on.date_format' => 'Vencimento inválido.',
            'installments.*.amount_cents.required' => 'Informe o valor de cada parcela.',
            'installments.*.amount_cents.integer' => 'Valor de parcela inválido.',
            'installments.*.amount_cents.min' => 'Cada parcela precisa ser maior que zero.',
            'auto_settle_method.required_if_accepted' => 'Escolha como a baixa automática é paga.',
            'supplier_id.exists' => 'Fornecedor não encontrado.',
            'category_id.exists' => 'Categoria não encontrada.',
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $total = (int) $this->input('total');
                $sum = array_sum(array_column($this->installmentRows(), 'amount_cents'));

                if ($sum !== $total) {
                    $validator->errors()->add('installments', 'A soma das parcelas (R$ '.Money::toInput($sum).') precisa ser igual ao total (R$ '.Money::toInput($total).').');
                }
            },
        ];
    }

    public function type(): FinanceType
    {
        return $this->title()->type ?? FinanceType::tryFrom((string) $this->input('type')) ?? FinanceType::Payable;
    }

    /**
     * @return array<string, mixed>
     */
    public function titleAttributes(): array
    {
        $data = $this->validated();
        $isPayable = $this->type() === FinanceType::Payable;

        return [
            'type' => $this->type(),
            'description' => $data['description'],
            'supplier_id' => $isPayable ? ($data['supplier_id'] ?? null) : null,
            'counterparty' => $data['counterparty'] ?? null,
            'category_id' => $data['category_id'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'issued_on' => $data['issued_on'],
            'total_cents' => $data['total'],
            'frequency' => $data['frequency'],
            'auto_settle' => (bool) ($data['auto_settle'] ?? false),
            'auto_settle_method' => $data['auto_settle_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * @return list<array{due_on: string, amount_cents: int}>
     */
    public function installmentRows(): array
    {
        $rows = $this->input('installments');

        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(fn ($row) => [
            'due_on' => (string) ($row['due_on'] ?? ''),
            'amount_cents' => (int) ($row['amount_cents'] ?? 0),
        ], $rows));
    }

    private function title(): ?FinanceTitle
    {
        $title = $this->route('title');

        return $title instanceof FinanceTitle ? $title : null;
    }

    private function money(mixed $value): int|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::parseToCents($value) ?? 'invalid';
    }
}
