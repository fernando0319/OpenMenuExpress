<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * How often the installments of a title fall due.
 */
enum FinanceFrequency: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Biweekly => 'Quinzenal',
            self::Monthly => 'Mensal',
        };
    }

    /**
     * Due date of the installment at $index (0 = the first one).
     * Monthly keeps the day, or the month's last day when it is shorter (31/01 → 28/02).
     */
    public function dueDate(CarbonImmutable $first, int $index): CarbonImmutable
    {
        return match ($this) {
            self::Weekly => $first->addWeeks($index),
            self::Biweekly => $first->addWeeks(2 * $index),
            self::Monthly => $first->addMonthsNoOverflow($index),
        };
    }
}
