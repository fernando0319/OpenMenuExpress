<?php

namespace App\Http\Requests\Pos;

use App\Enums\PaymentMethod;
use App\Rules\MoneyAmount;
use App\Services\Cash\Payment;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payments handed over at the counter for an order or a table's bill,
 * with the optional discount and service fee choice of a bill.
 */
class ReceivePaymentRequest extends FormRequest
{
    /**
     * @return list<Payment>
     */
    public function payments(): array
    {
        $payments = [];

        foreach ((array) $this->validated('payments') as $payment) {
            if (is_array($payment)) {
                $payments[] = new Payment(
                    PaymentMethod::from((string) $payment['method']),
                    Money::parseToCents($payment['amount']) ?? 0,
                );
            }
        }

        return $payments;
    }

    public function discountCents(): int
    {
        return Money::parseToCents($this->validated('discount') ?: '0') ?? 0;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'payments' => ['required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount' => ['required', new MoneyAmount(minCents: 1)],
            'discount' => ['nullable', new MoneyAmount],
            'service_fee' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payments.required' => 'Informe como o cliente pagou.',
            'payments.*.amount.required' => 'Informe o valor de cada forma de pagamento.',
        ];
    }
}
