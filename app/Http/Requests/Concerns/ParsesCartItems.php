<?php

namespace App\Http\Requests\Concerns;

use App\Services\Orders\CartPricer;

/**
 * Turns the validated "items" array into the typed shape CartPricer expects.
 *
 * @phpstan-import-type ItemInput from CartPricer
 */
trait ParsesCartItems
{
    /**
     * @return list<ItemInput>
     */
    protected function items(): array
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
                'product_id' => is_numeric($item['product_id'] ?? null) ? (int) $item['product_id'] : 0,
                'quantity' => is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0,
                'notes' => $this->nullableString($item['notes'] ?? null),
                'options' => $options,
                'pizza' => $this->pizza($item['pizza'] ?? null),
            ];
        }

        return $items;
    }

    /**
     * Flavors (in the order chosen) and crust of a pizza size.
     *
     * @return array{flavor_ids: list<int>, crust_id: int|null}|null
     */
    protected function pizza(mixed $pizza): ?array
    {
        if (! is_array($pizza)) {
            return null;
        }

        $flavors = is_array($pizza['flavor_ids'] ?? null) ? $pizza['flavor_ids'] : [];

        return [
            'flavor_ids' => array_values(array_map('intval', array_filter($flavors, 'is_numeric'))),
            'crust_id' => is_numeric($pizza['crust_id'] ?? null) ? (int) $pizza['crust_id'] : null,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
            'items.*.notes' => ['nullable', 'string', 'max:200'],
            'items.*.options' => ['array', 'max:50'],
            'items.*.options.*.option_id' => ['required', 'integer'],
            'items.*.options.*.quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'items.*.pizza' => ['nullable', 'array'],
            'items.*.pizza.flavor_ids' => ['array', 'max:8'],
            'items.*.pizza.flavor_ids.*' => ['integer'],
            'items.*.pizza.crust_id' => ['nullable', 'integer'],
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
