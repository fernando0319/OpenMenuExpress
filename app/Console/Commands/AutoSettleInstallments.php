<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Installments marked for automatic settlement (direct debit, card on file) are settled on their
 * due date. Hourly, so each store's local midnight is honored.
 */
#[Signature('app:auto-settle-installments')]
#[Description('Settle installments marked as automatic on their due date')]
class AutoSettleInstallments extends Command
{
    public function handle(FinanceService $finance): int
    {
        $count = $finance->autoSettleDue();

        $this->info("{$count} parcela(s) baixada(s) automaticamente.");

        return self::SUCCESS;
    }
}
