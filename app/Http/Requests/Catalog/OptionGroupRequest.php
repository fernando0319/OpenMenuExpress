<?php

namespace App\Http\Requests\Catalog;

use App\Enums\PricingMode;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OptionGroupRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $options = $this->input('options');

        if (! is_array($options)) {
            return;
        }

        $this->merge([
            'options' => array_map(function (mixed $option): mixed {
                if (! is_array($option)) {
                    return $option;
                }

                $price = $option['price'] ?? '';

                return [...$option, 'price_cents' => $price === '' ? 0 : Money::parseToCents($price)];
            }, $options),
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
            'name' => ['required', 'string', 'max:80'],
            'min_choices' => ['required', 'integer', 'min:0', 'max:50'],
            'max_choices' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_choices'],
            'pricing_mode' => ['required', Rule::enum(PricingMode::class)],
            'allows_quantity' => ['boolean'],
            'is_active' => ['boolean'],
            'options' => ['required', 'array', 'min:1', 'max:100'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:80'],
            'options.*.description' => ['nullable', 'string', 'max:160'],
            'options.*.price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'options.*.is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_choices.gte' => 'O máximo deve ser maior ou igual ao mínimo.',
            'options.required' => 'Cadastre pelo menos uma opção.',
            'options.*.name.required' => 'Informe o nome da opção.',
            'options.*.price_cents.required' => 'Preço inválido. Use o formato 12,50.',
            'options.*.price_cents.integer' => 'Preço inválido. Use o formato 12,50.',
        ];
    }
}
