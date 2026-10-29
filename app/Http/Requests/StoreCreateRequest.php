<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreCreateRequest extends FormRequest
{
    /**
     * Addresses that can never belong to a store: subdomains we use and every
     * first path segment of the app's own routes (the menu lives at /{slug}).
     */
    public const RESERVED_SLUGS = [
        'www', 'api', 'app', 'admin', 'assinatura', 'billing', 'painel', 'mail', 'static', 'cdn', 'status', 'suporte', 'build', 'assets', 'finance', 'financeiro',
        'caixa', 'cash', 'catalog', 'couriers', 'deliveries', 'dashboard', 'email', 'entregador', 'entregadores', 'entregas', 'equipe', 'forgot-password', 'login', 'logout', 'marketing', 'mesas', 'orders', 'passkeys', 'pdv', 'pos', 'register', 'relatorios', 'reports',
        'reset-password', 'settings', 'storage', 'store-settings', 'store-status', 'stores', 'tables', 'team', 'two-factor-challenge', 'up', 'user',
    ];

    protected function prepareForValidation(): void
    {
        $whatsapp = preg_replace('/\D/', '', (string) $this->input('whatsapp'));

        if ($whatsapp !== null && in_array(strlen($whatsapp), [10, 11], true)) {
            $whatsapp = '55'.$whatsapp;
        }

        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
            'whatsapp' => $whatsapp === '' ? null : $whatsapp,
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
            'name' => ['required', 'string', 'max:120'],
            'slug' => [
                'required', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('stores', 'slug'),
                Rule::notIn(self::RESERVED_SLUGS),
            ],
            'whatsapp' => ['nullable', 'string', 'regex:/^55\d{10,11}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.unique' => 'Este endereço já está em uso. Escolha outro.',
            'slug.not_in' => 'Este endereço é reservado. Escolha outro.',
            'slug.regex' => 'Use apenas letras minúsculas, números e hífens.',
            'whatsapp.regex' => 'Informe o WhatsApp com DDD, por exemplo (11) 99999-8888.',
        ];
    }
}
