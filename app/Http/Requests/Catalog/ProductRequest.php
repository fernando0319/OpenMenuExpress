<?php

namespace App\Http\Requests\Catalog;

use App\Models\Product;
use App\Support\CurrentStore;
use App\Support\ImageStorage;
use App\Support\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $isPizza = $this->input('kind') === Product::KIND_PIZZA;
        // A pizza size is priced by the flavor table; its own price is not used.
        $promotional = $isPizza ? null : $this->input('promotional_price');

        $this->merge([
            'kind' => $isPizza ? Product::KIND_PIZZA : 'standard',
            'price_cents' => $isPizza ? 0 : Money::parseToCents($this->input('price')),
            'promotional_price_cents' => $promotional === null || $promotional === ''
                ? null
                : (Money::parseToCents($promotional) ?? 'invalid'),
            'sku' => $this->input('sku') ?: null,
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $storeId = app(CurrentStore::class)->require()->id;
        $product = $this->route('product');

        return [
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('store_id', $storeId)],
            'kind' => ['required', Rule::in(['standard', Product::KIND_PIZZA])],
            'pizza_max_flavors' => ['nullable', 'required_if:kind,pizza', 'integer', 'min:1', 'max:8'],
            'pizza_slices' => ['nullable', 'integer', 'min:1', 'max:64'],
            'pizza_diameter_cm' => ['nullable', 'integer', 'min:10', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sku' => [
                'nullable', 'string', 'max:40',
                Rule::unique('products', 'sku')
                    ->where('store_id', $storeId)
                    ->ignore($product instanceof Product ? $product->id : null),
            ],
            'price_cents' => ['required', 'integer', 'min:0', 'max:10000000'],
            'promotional_price_cents' => ['nullable', 'integer', 'min:0', 'lt:price_cents'],
            'image' => ['nullable', ...ImageStorage::rules()],
            'remove_image' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_active' => ['boolean'],
            'track_stock' => ['boolean'],
            'stock_quantity' => ['nullable', 'required_if_accepted:track_stock', 'integer', 'min:-100000', 'max:1000000'],
            'option_groups' => ['array', 'max:20'],
            'option_groups.*.id' => [
                'required', 'integer', 'distinct',
                Rule::exists('option_groups', 'id')->where('store_id', $storeId),
            ],
            'option_groups.*.min_choices' => ['nullable', 'integer', 'min:0', 'max:50'],
            'option_groups.*.max_choices' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'price_cents.required' => 'Preço inválido. Use o formato 12,50.',
            'price_cents.integer' => 'Preço inválido. Use o formato 12,50.',
            'promotional_price_cents.integer' => 'Preço promocional inválido. Use o formato 12,50.',
            'promotional_price_cents.lt' => 'O preço promocional deve ser menor que o preço normal.',
            'stock_quantity.required_if_accepted' => 'Informe o estoque atual.',
            'pizza_max_flavors.required_if' => 'Informe quantos sabores cabem neste tamanho.',
            'pizza_max_flavors.max' => 'No máximo 8 sabores por pizza.',
            'option_groups.*.id.distinct' => 'O mesmo grupo foi adicionado duas vezes.',
        ];
    }
}
