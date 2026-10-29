<?php

namespace App\Http\Requests\Finance;

use App\Support\BrazilianDocument;
use App\Support\Phone;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SupplierRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $document = trim((string) $this->input('document'));
        $phone = trim((string) $this->input('phone'));

        $this->merge([
            'document' => $document === '' ? null : (BrazilianDocument::normalize($document) ?? 'invalid'),
            'phone' => $phone === '' ? null : (Phone::normalize($phone) ?? 'invalid'),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'document' => ['nullable', 'regex:/^(\d{11}|\d{14})$/'],
            'contact_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'regex:/^55\d{10,11}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do fornecedor.',
            'document.regex' => 'CPF ou CNPJ inválido.',
            'phone.regex' => 'Telefone inválido. Use DDD + número.',
            'email.email' => 'E-mail inválido.',
        ];
    }
}
