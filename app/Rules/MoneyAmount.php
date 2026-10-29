<?php

namespace App\Rules;

use App\Support\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount typed by a person ("12,50"), validated under its own field name so the
 * error shows next to the input. Read the cents afterwards with Money::parseToCents.
 */
final class MoneyAmount implements ValidationRule
{
    public function __construct(private readonly int $minCents = 0) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cents = Money::parseToCents($value);

        if ($cents === null) {
            $fail('Valor inválido. Use o formato 30,00.');

            return;
        }

        if ($cents < $this->minCents) {
            $fail('Informe um valor maior que zero.');
        }
    }
}
