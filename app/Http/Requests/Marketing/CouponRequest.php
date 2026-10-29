<?php

namespace App\Http\Requests\Marketing;

use App\Enums\CouponType;
use App\Models\Coupon;
use App\Support\CurrentStore;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

class CouponRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Coupon::normalizeCode((string) $this->input('code')),
            'amount_cents' => $this->money('amount'),
            'max_discount_cents' => $this->money('max_discount'),
            'min_subtotal_cents' => $this->money('min_subtotal') ?? 0,
            'starts_at' => $this->localDate('starts_at'),
            'ends_at' => $this->localDate('ends_at'),
            'fulfillment' => $this->input('fulfillment') ?: null,
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
        $coupon = $this->route('coupon');

        return [
            'code' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[A-Z0-9_-]+$/',
                Rule::unique('coupons', 'code')->where('store_id', $storeId)->ignore($coupon instanceof Coupon ? $coupon->id : null),
            ],
            'description' => ['nullable', 'string', 'max:160'],
            'type' => ['required', Rule::enum(CouponType::class)],
            'percent' => ['nullable', 'required_if:type,percent', 'integer', 'between:1,100'],
            'amount_cents' => ['nullable', 'required_if:type,fixed', 'integer', 'min:1'],
            'max_discount_cents' => ['nullable', 'integer', 'min:1'],
            'min_subtotal_cents' => ['required', 'integer', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'first_order_only' => ['boolean'],
            'fulfillment' => ['nullable', 'in:delivery,pickup'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.regex' => 'Use letras, números, hífen ou sublinhado, sem espaços.',
            'code.unique' => 'Já existe um cupom com este código.',
            'percent.required_if' => 'Informe a porcentagem.',
            'amount_cents.required_if' => 'Informe o valor do desconto (ex.: 10,00).',
            'amount_cents.integer' => 'Valor inválido. Use o formato 10,00.',
            'max_discount_cents.integer' => 'Valor inválido. Use o formato 30,00.',
            'min_subtotal_cents.integer' => 'Valor inválido. Use o formato 50,00.',
            'ends_at.after' => 'O fim precisa ser depois do início.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function couponData(): array
    {
        $data = $this->validated();

        // Keep only the value that belongs to the chosen type.
        $type = CouponType::from($data['type']);
        $data['percent'] = $type === CouponType::Percent ? $data['percent'] : null;
        $data['amount_cents'] = $type === CouponType::Fixed ? $data['amount_cents'] : null;
        $data['max_discount_cents'] = $type === CouponType::Percent ? ($data['max_discount_cents'] ?? null) : null;

        return $data;
    }

    private function money(string $field): int|string|null
    {
        $value = $this->input($field);

        if ($value === null || $value === '') {
            return null;
        }

        return Money::parseToCents($value) ?? 'invalid';
    }

    /**
     * "2026-10-20T18:00" typed in the store's timezone → UTC.
     */
    private function localDate(string $field): ?string
    {
        $value = $this->input($field);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, app(CurrentStore::class)->require()->timezone)->utc()->toDateTimeString();
        } catch (Throwable) {
            return $value;
        }
    }
}
