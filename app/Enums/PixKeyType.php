<?php

namespace App\Enums;

enum PixKeyType: string
{
    case Cpf = 'cpf';
    case Phone = 'phone';
    case Email = 'email';
    case Random = 'random';

    public function label(): string
    {
        return match ($this) {
            self::Cpf => 'CPF',
            self::Phone => 'Celular',
            self::Email => 'E-mail',
            self::Random => 'Chave aleatória',
        };
    }
}
