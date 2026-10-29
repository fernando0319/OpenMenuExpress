<?php

namespace App\Services\Finance;

use App\Enums\FinanceFrequency;
use Carbon\CarbonImmutable;

/**
 * Splits a total into installments. The cents that do not divide evenly go to the first
 * installments, so the sum is always the exact total (100,00 in 3 = 33,34 + 33,33 + 33,33).
 */
final class InstallmentPlan
{
    public const MAX_INSTALLMENTS = 120;

    /**
     * @return list<array{due_on: string, amount_cents: int}>
     */
    public static function split(int $totalCents, int $count, CarbonImmutable $firstDue, FinanceFrequency $frequency): array
    {
        $count = max(1, min(self::MAX_INSTALLMENTS, $count));
        $base = intdiv($totalCents, $count);
        $remainder = $totalCents - $base * $count;
        $plan = [];

        for ($index = 0; $index < $count; $index++) {
            $plan[] = [
                'due_on' => $frequency->dueDate($firstDue, $index)->format('Y-m-d'),
                'amount_cents' => $base + ($index < $remainder ? 1 : 0),
            ];
        }

        return $plan;
    }
}
