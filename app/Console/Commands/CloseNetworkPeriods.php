<?php

namespace App\Console\Commands;

use App\Services\CourierNetwork\NetworkBilling;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes the finished days and weeks of the courier network: store bills and courier payouts.
 */
#[Signature('app:close-network-periods')]
#[Description('Close delivered network runs into store bills and weekly courier payouts')]
class CloseNetworkPeriods extends Command
{
    public function handle(NetworkBilling $billing): int
    {
        $bills = $billing->closeBills();
        $payouts = $billing->closePayouts();

        $this->info("{$bills} fatura(s) e {$payouts} repasse(s) atualizados.");

        return self::SUCCESS;
    }
}
