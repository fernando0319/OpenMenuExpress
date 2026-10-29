<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancePaymentMethod;
use App\Support\CurrentStore;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SettleInstallmentRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');

        $this->merge([
            'amount' => $amount === null || $amount === '' ? null : (Money::parseToCents($amount) ?? 'invalid'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $today = CarbonImmutable::now(app(CurrentStore::class)->require()->timezone)->format('Y-m-d');

        return [
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'amount' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', Rule::enum(FinancePaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:255'],
            'through_cash' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paid_on.required' => 'Informe a data do pagamento.',
            'paid_on.before_or_equal' => 'A data não pode ser no futuro.',
            'amount.required' => 'Informe o valor pago.',
            'amount.integer' => 'Valor inválido. Use o formato 150,00.',
            'amount.min' => 'O valor precisa ser maior que zero.',
            'payment_method.required' => 'Escolha a forma de pagamento.',
        ];
    }
}
