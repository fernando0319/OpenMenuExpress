<?php

namespace App\Enums;

/**
 * How a payable was paid or a receivable was received.
 */
enum FinancePaymentMethod: string
{
    case Cash = 'cash';
    case Pix = 'pix';
    case Boleto = 'boleto';
    case Transfer = 'transfer';
    case DebitCard = 'debit_card';
    case CreditCard = 'credit_card';
    case AutoDebit = 'auto_debit';
    case Check = 'check';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Dinheiro',
            self::Pix => 'PIX',
            self::Boleto => 'Boleto',
            self::Transfer => 'Transferência (TED/DOC)',
            self::DebitCard => 'Cartão de débito',
            self::CreditCard => 'Cartão de crédito',
            self::AutoDebit => 'Débito automático',
            self::Check => 'Cheque',
            self::Other => 'Outro',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $method) => ['value' => $method->value, 'label' => $method->label()], self::cases());
    }
}
