<?php

namespace App\Http\Requests\Courier;

use App\Enums\PixKeyType;
use App\Enums\Vehicle;
use App\Support\BrazilianDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The courier's own data: contact, city, vehicle and the PIX key payouts go to.
 * Normalized before validation (digits only, plate in capitals, phone with 55).
 */
class CourierProfileRequest extends FormRequest
{
    public const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => self::phone($this->input('phone')),
            'state' => strtoupper(trim((string) $this->input('state'))),
            'city' => trim((string) $this->input('city')),
            'vehicle_plate' => $this->filled('vehicle_plate')
                ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->input('vehicle_plate')) ?? '')
                : null,
            'pix_key' => $this->pixKey(),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^55\d{10,11}$/'],
            'city' => ['required', 'string', 'max:80'],
            'state' => ['required', Rule::in(self::STATES)],
            'vehicle' => ['required', Rule::enum(Vehicle::class)],
            'vehicle_plate' => [
                Rule::requiredIf(fn () => Vehicle::tryFrom((string) $this->input('vehicle'))?->hasPlate() ?? false),
                'nullable',
                'regex:/^[A-Z]{3}\d[A-Z0-9]\d{2}$/',
            ],
            'pix_key_type' => ['required', Rule::enum(PixKeyType::class)],
            'pix_key' => ['required', 'string', 'max:140', $this->pixKeyRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Informe o celular com DDD.',
            'vehicle_plate.required' => 'Informe a placa do veículo.',
            'vehicle_plate.regex' => 'Placa inválida. Use o formato ABC1D23 ou ABC1234.',
            'pix_key.regex' => 'Chave PIX inválida para o tipo escolhido.',
        ];
    }

    /**
     * Profile fields, normalized and validated.
     *
     * @return array{phone: string, city: string, state: string, vehicle: string, vehicle_plate: string|null, pix_key_type: string, pix_key: string}
     */
    public function profile(): array
    {
        $data = $this->validated();
        $vehicle = Vehicle::from($data['vehicle']);

        return [
            'phone' => $data['phone'],
            'city' => $data['city'],
            'state' => $data['state'],
            'vehicle' => $vehicle->value,
            'vehicle_plate' => $vehicle->hasPlate() ? $data['vehicle_plate'] : null,
            'pix_key_type' => $data['pix_key_type'],
            'pix_key' => $data['pix_key'],
        ];
    }

    public static function phone(mixed $value): string
    {
        $digits = preg_replace('/\D/', '', (string) $value) ?? '';

        return in_array(strlen($digits), [10, 11], true) ? '55'.$digits : $digits;
    }

    private function pixKey(): string
    {
        $key = trim((string) $this->input('pix_key'));

        return match (PixKeyType::tryFrom((string) $this->input('pix_key_type'))) {
            PixKeyType::Cpf => preg_replace('/\D/', '', $key) ?? '',
            PixKeyType::Phone => '+'.self::phone($key),
            PixKeyType::Email, PixKeyType::Random => strtolower($key),
            null => $key,
        };
    }

    private function pixKeyRule(): string
    {
        return match (PixKeyType::tryFrom((string) $this->input('pix_key_type'))) {
            PixKeyType::Cpf => 'regex:/^\d{11}$/',
            PixKeyType::Phone => 'regex:/^\+55\d{10,11}$/',
            PixKeyType::Email => 'email',
            PixKeyType::Random => 'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            null => 'string',
        };
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('pix_key_type') === PixKeyType::Cpf->value && BrazilianDocument::normalize($this->input('pix_key')) === null) {
                    $validator->errors()->add('pix_key', 'CPF da chave PIX inválido.');
                }
            },
        ];
    }
}
