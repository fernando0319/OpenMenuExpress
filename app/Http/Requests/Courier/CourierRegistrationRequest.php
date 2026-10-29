<?php

namespace App\Http\Requests\Courier;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\NetworkCourier;
use App\Support\BrazilianDocument;
use App\Support\PasswordPolicy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Joining the courier network. A visitor also creates the account (name, e-mail, password);
 * someone already signed in only adds the courier data.
 */
class CourierRegistrationRequest extends CourierProfileRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $this->merge(['cpf' => preg_replace('/\D/', '', (string) $this->input('cpf')) ?? '']);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $account = $this->user() === null ? [...$this->profileRules(), 'password' => $this->passwordRules(PasswordPolicy::light())] : [];

        return [
            ...$account,
            ...parent::rules(),
            'cpf' => ['required', 'digits:11', Rule::unique(NetworkCourier::class, 'cpf')],
            'document' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
            'terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'cpf.unique' => 'Este CPF já está cadastrado na rede.',
            'document.required' => 'Envie a foto da CNH ou de um documento com foto.',
            'document.max' => 'O arquivo pode ter até 8 MB.',
            'terms.accepted' => 'Aceite os termos para continuar.',
        ];
    }

    public function after(): array
    {
        return [
            ...parent::after(),
            function ($validator): void {
                if (strlen((string) $this->input('cpf')) === 11 && BrazilianDocument::normalize($this->input('cpf')) === null) {
                    $validator->errors()->add('cpf', 'CPF inválido.');
                }
            },
        ];
    }
}
