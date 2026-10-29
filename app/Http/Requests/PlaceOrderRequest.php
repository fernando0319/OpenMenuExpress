<?php

namespace App\Http\Requests;

use App\Enums\OnlinePaymentMethod;
use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ParsesCartItems;
use App\Services\Orders\PlaceOnlineOrder;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of the checkout payload. Business rules (prices, option limits, stock, fees)
 * are checked by PlaceOnlineOrder.
 *
 * @phpstan-import-type OrderInput from PlaceOnlineOrder
 */
class PlaceOrderRequest extends FormRequest
{
    use ParsesCartItems;

    /**
     * The validated payload in the exact shape PlaceOnlineOrder expects.
     *
     * @return OrderInput
     */
    public function orderInput(): array
    {
        $address = $this->validated('address');
        $address = is_array($address) ? $address : [];

        return [
            'fulfillment' => $this->string('fulfillment')->toString(),
            'customer' => [
                'name' => $this->string('customer.name')->toString(),
                'phone' => $this->string('customer.phone')->toString(),
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
            'payment_method' => $this->string('payment_method')->toString(),
            'change_for' => $this->nullableString($this->validated('change_for')),
            'no_change' => $this->boolean('no_change'),
            'notes' => $this->nullableString($this->validated('notes')),
            'scheduled_for' => $this->nullableString($this->validated('scheduled_for')),
            'coupon_code' => $this->nullableString($this->validated('coupon_code')),
            'items' => $this->items(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');

        if (is_array($customer)) {
            $this->merge([
                'customer' => [...$customer, 'phone' => Phone::normalize($customer['phone'] ?? null) ?? ($customer['phone'] ?? null)],
            ]);
        }
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
            'customer.name' => ['required', 'string', 'max:80'],
            'customer.phone' => ['required', 'string', 'regex:/^55\d{10,11}$/'],
            'address.zip' => ['nullable', 'string', 'max:9'],
            'address.street' => ['required_if:fulfillment,delivery', 'nullable', 'string', 'max:120'],
            'address.number' => ['required_if:fulfillment,delivery', 'nullable', 'string', 'max:20'],
            'address.district' => ['nullable', 'string', 'max:80'],
            'address.complement' => ['nullable', 'string', 'max:80'],
            'address.reference' => ['nullable', 'string', 'max:120'],
            'delivery_zone_id' => ['nullable', 'integer'],
            'payment_method' => ['required', Rule::in([...array_column(PaymentMethod::cases(), 'value'), ...array_column(OnlinePaymentMethod::cases(), 'value')])],
            'change_for' => ['nullable', 'string', 'max:20'],
            'no_change' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
            'scheduled_for' => ['nullable', 'string', 'max:40'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            ...$this->itemRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer.name.required' => 'Informe seu nome.',
            'customer.phone.required' => 'Informe seu WhatsApp.',
            'customer.phone.regex' => 'Informe o WhatsApp com DDD, por exemplo (11) 99999-8888.',
            'address.street.required_if' => 'Informe a rua.',
            'address.number.required_if' => 'Informe o número.',
            'payment_method.required' => 'Escolha a forma de pagamento.',
            'items.required' => 'Seu carrinho está vazio.',
        ];
    }
}
