<?php

namespace App\Http\Requests\Orders;

use App\Enums\PaymentMethod;
use App\Http\Requests\Concerns\ParsesCartItems;
use App\Services\Orders\EditOrder;
use App\Support\Money;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape of an order edit. Items with an "id" are already in the order (only quantity and
 * notes change); items without one are new and carry product and options like a sale.
 *
 * @phpstan-import-type EditInput from EditOrder
 * @phpstan-import-type EditItem from EditOrder
 */
class EditOrderRequest extends FormRequest
{
    use ParsesCartItems;

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');
        $toCents = fn (mixed $value): int|string|null => $value === null || $value === '' ? null : (Money::parseToCents($value) ?? 'invalid');

        $this->merge([
            'customer' => is_array($customer)
                ? [...$customer, 'phone' => Phone::normalize($customer['phone'] ?? null) ?? (($customer['phone'] ?? '') === '' ? null : $customer['phone'])]
                : $customer,
            'discount' => $toCents($this->input('discount')),
            'delivery_fee' => $toCents($this->input('delivery_fee')),
            'change_for' => $toCents($this->input('change_for')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:80'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.product_id' => ['required_without:items.*.id', 'nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
            'items.*.options' => ['array', 'max:50'],
            'items.*.options.*.option_id' => ['required', 'integer'],
            'items.*.options.*.quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'items.*.pizza' => ['nullable', 'array'],
            'items.*.pizza.flavor_ids' => ['array', 'max:8'],
            'items.*.pizza.flavor_ids.*' => ['integer'],
            'items.*.pizza.crust_id' => ['nullable', 'integer'],
            'customer.name' => ['nullable', 'string', 'max:80'],
            'customer.phone' => ['nullable', 'string', 'regex:/^55\d{10,11}$/'],
            'address.zip' => ['nullable', 'string', 'max:9'],
            'address.street' => ['nullable', 'string', 'max:120'],
            'address.number' => ['nullable', 'string', 'max:20'],
            'address.district' => ['nullable', 'string', 'max:80'],
            'address.complement' => ['nullable', 'string', 'max:80'],
            'address.reference' => ['nullable', 'string', 'max:120'],
            'delivery_fee' => ['nullable', 'integer', 'min:0'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'change_for' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notify_customer' => ['boolean'],
            'refund_difference' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'O pedido precisa de pelo menos um item.',
            'items.*.quantity.min' => 'A quantidade mínima é 1. Para tirar o item, use o botão de remover.',
            'customer.phone.regex' => 'Informe o WhatsApp com DDD, por exemplo (11) 99999-8888.',
            'delivery_fee.integer' => 'Taxa de entrega inválida.',
            'discount.integer' => 'Desconto inválido.',
            'change_for.integer' => 'Valor do troco inválido.',
        ];
    }

    /**
     * @return EditInput
     */
    public function editInput(): array
    {
        $address = $this->validated('address');
        $address = is_array($address) ? $address : [];

        return [
            'items' => $this->editItems(),
            'customer' => [
                'name' => $this->text($this->validated('customer.name')),
                'phone' => $this->text($this->validated('customer.phone')),
            ],
            'address' => [
                'zip' => $this->text($address['zip'] ?? null),
                'street' => $this->text($address['street'] ?? null),
                'number' => $this->text($address['number'] ?? null),
                'district' => $this->text($address['district'] ?? null),
                'complement' => $this->text($address['complement'] ?? null),
                'reference' => $this->text($address['reference'] ?? null),
            ],
            'delivery_fee_cents' => $this->cents('delivery_fee'),
            'discount_cents' => $this->cents('discount') ?? 0,
            'payment_method' => $this->text($this->validated('payment_method')),
            'change_for_cents' => $this->cents('change_for'),
            'notes' => $this->text($this->validated('notes')),
            'reason' => $this->text($this->validated('reason')),
            'notify_customer' => $this->boolean('notify_customer'),
            'refund_difference' => $this->boolean('refund_difference'),
        ];
    }

    /**
     * @return list<EditItem>
     */
    private function editItems(): array
    {
        $items = [];
        $raw = $this->validated('items');

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $options = [];

            foreach (is_array($item['options'] ?? null) ? $item['options'] : [] as $option) {
                if (is_array($option) && is_numeric($option['option_id'] ?? null)) {
                    $options[] = [
                        'option_id' => (int) $option['option_id'],
                        'quantity' => is_numeric($option['quantity'] ?? null) ? (int) $option['quantity'] : null,
                    ];
                }
            }

            $items[] = [
                'id' => is_numeric($item['id'] ?? null) ? (int) $item['id'] : null,
                'product_id' => is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : null,
                'quantity' => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 1,
                'notes' => $this->text($item['notes'] ?? null),
                'options' => $options,
                'pizza' => $this->pizza($item['pizza'] ?? null),
            ];
        }

        return $items;
    }

    private function cents(string $key): ?int
    {
        $value = $this->validated($key);

        return is_int($value) ? $value : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
