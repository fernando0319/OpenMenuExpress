<?php

namespace App\Enums;

/**
 * How often a store's network runs are closed into a bill.
 */
enum BillingCycle: string
{
    /** Each day becomes a bill, due the next day. */
    case Daily = 'daily';

    /** Monday to Sunday becomes a bill, due on Tuesday. */
    case Weekly = 'weekly';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diária',
            self::Weekly => 'Semanal',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Daily => 'As corridas de cada dia viram uma fatura, paga no dia seguinte.',
            self::Weekly => 'As corridas de segunda a domingo viram uma fatura, paga até terça-feira.',
        };
    }
}
