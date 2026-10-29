<?php

namespace App\Http\Requests\Pos;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ParsesCartItems;
use App\Services\Orders\PlacePosOrder;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of a PDV sale. Amounts arrive as typed by the attendant ("12,50") and are
 * turned into cents here; the sale rules live in PlacePosOrder.
 *
 * @phpstan-import-type PosInput from PlacePosOrder
 */
class PosOrderRequest extends FormRequest
{
    use ParsesCartItems;

    /**
     * @return PosInput
     */
    public function posInput(): array
    {
        $address = $this->validated('address');
        $address = is_array($address) ? $address : [];
        $payments = [];

        foreach ((array) $this->validated('payments', []) as $payment) {
            if (is_array($payment) && is_string($payment['method'] ?? null) && is_int($payment['amount_cents'] ?? null)) {
                $payments[] = ['method' => $payment['method'], 'amount_cents' => $payment['amount_cents']];
            }
        }

        return [
            'fulfillment' => $this->string('fulfillment')->toString(),
            'customer' => [
                'name' => $this->nullableString($this->validated('customer.name')),
                'phone' => $this->nullableString($this->validated('customer.phone')),
            ],
            'address' => [
                'zip' => $this->nullableString($address['zip'] ?? null),
                'street' => $this->nullableString($address['street'] ?? null),
                'number' => $this->nullableString($address['number'] ?? null),
                'district' => $this->nullableString($address['district'] ?? null),
                'complement' => $this->nullableString($address['complement'] ?? null),
                'reference' => $this->nullableString($address['reference'] ?? null),
            ],
            'delivery_zone_id' => $this->filled('delivery_zone_id') ? $this->integer('delivery_zone_id') : null,
            'courier_id' => $this->filled('courier_id') ? $this->integer('courier_id') : null,
            'delivery_fee_cents' => $this->centsOrNull('delivery_fee_cents'),
            'discount_cents' => $this->centsOrNull('discount_cents') ?? 0,
            'notes' => $this->nullableString($this->validated('notes')),
            'pay_later' => $this->boolean('pay_later'),
            'payment_method' => $this->nullableString($this->validated('payment_method')),
            'change_for_cents' => $this->centsOrNull('change_for_cents'),
            'payments' => $payments,
            'complete_now' => $this->boolean('complete_now'),
            'items' => $this->items(),
        ];
    }

    private function centsOrNull(string $key): ?int
    {
        $value = $this->validated($key);

        return is_int($value) ? $value : null;
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');
        $toCents = fn (mixed $value): int|string|null => $value === null || $value === '' ? null : (Money::parseToCents($value) ?? 'invalid');

        $payments = $this->input('payments');

        $this->merge([
            'customer' => is_array($customer)
                ? [...$customer, 'phone' => Phone::normalize($customer['phone'] ?? null) ?? ($customer['phone'] ?? null)]
                : $customer,
            'discount_cents' => $toCents($this->input('discount')),
            'delivery_fee_cents' => $toCents($this->input('delivery_fee')),
            'change_for_cents' => $toCents($this->input('change_for')),
            'payments' => is_array($payments)
                ? array_map(fn (mixed $payment) => is_array($payment)
                    ? [...$payment, 'amount_cents' => $toCents($payment['amount'] ?? null) ?? 'invalid']
                    : $payment, $payments)
                : $payments,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fulfillment' => ['required', 'string', Rule::in(['delivery', 'pickup'])],
            'customer.name' => ['nullable', 'string', 'max:80'],
            'customer.phone' => ['nullable', 'string', 'regex:/^55\d{10,11}$/'],
            'address.zip' => ['nullable', 'string', 'max:9'],
            'address.street' => ['nullable', 'string', 'max:120'],
            'address.number' => ['nullable', 'string', 'max:20'],
            'address.district' => ['nullable', 'string', 'max:80'],
            'address.complement' => ['nullable', 'string', 'max:80'],
            'address.reference' => ['nullable', 'string', 'max:120'],
            'delivery_zone_id' => ['nullable', 'integer'],
            'delivery_fee_cents' => ['nullable', 'integer', 'min:0'],
            'discount_cents' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'pay_later' => ['boolean'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'change_for_cents' => ['nullable', 'integer', 'min:0'],
            'payments' => ['exclude_if:pay_later,true', 'required', 'array', 'min:1', 'max:5'],
            'payments.*.method' => ['required', Rule::enum(PaymentMethod::class)],
            'payments.*.amount_cents' => ['required', 'integer', 'min:1'],
            'complete_now' => ['boolean'],
            'courier_id' => ['nullable', 'integer'],
            ...$this->itemRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer.phone.regex' => 'Informe o WhatsApp com DDD, por exemplo (11) 99999-8888.',
            'delivery_fee_cents.integer' => 'Taxa de entrega inválida.',
            'discount_cents.integer' => 'Desconto inválido.',
            'change_for_cents.integer' => 'Valor do troco inválido.',
            'payments.required' => 'Informe como o cliente pagou.',
            'payments.*.amount_cents.integer' => 'Valor de pagamento inválido.',
            'payments.*.amount_cents.min' => 'Informe um valor maior que zero em cada forma de pagamento.',
            'items.required' => 'Adicione ao menos um produto.',
        ];
    }
}
