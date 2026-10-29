<?php

namespace App\Console\Commands;

use App\Payments\OnlinePayments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for lost notifications: checks open charges at the providers and cancels the
 * orders whose payment time ran out.
 */
#[Signature('app:sync-online-payments')]
#[Description('Check pending online payments and expire the late ones')]
class SyncOnlinePayments extends Command
{
    public function handle(OnlinePayments $payments): int
    {
        $this->info($payments->syncPending().' pagamento(s) verificado(s).');

        return self::SUCCESS;
    }
}
