<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // Staff who sign in with a username may have no e-mail at all.
        if ($this->user()->username !== null) {
            $rules['email'] = ['nullable', ...array_values(array_filter($rules['email'], fn ($rule) => $rule !== 'required'))];
        }

        return $rules;
    }
}
