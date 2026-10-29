<?php

namespace App\Http\Requests\Tables;

use App\Http\Requests\Concerns\ParsesCartItems;
use App\Services\Orders\CartPricer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A round of items the waiter sends to the kitchen.
 *
 * @phpstan-import-type ItemInput from CartPricer
 */
class TabRoundRequest extends FormRequest
{
    use ParsesCartItems;

    /**
     * @return list<ItemInput>
     */
    public function roundItems(): array
    {
        return $this->items();
    }

    public function notes(): ?string
    {
        return $this->nullableString($this->validated('notes'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:500'],
            ...$this->itemRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Adicione ao menos um item.',
        ];
    }
}
