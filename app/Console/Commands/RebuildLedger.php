<?php

namespace App\Console\Commands;

use App\Services\Ledger\Ledger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Writes the ledger from the cash register and online payments already in the database: run once
 * after updating an existing install, or any time to fill in quantities a provider could not tell
 * before. Recording again never duplicates.
 */
#[Signature('app:rebuild-ledger {--store= : Only this store id}')]
#[Description('Record past cash sales and online payments in the ledger')]
class RebuildLedger extends Command
{
    public function handle(Ledger $ledger): int
    {
        $store = $this->option('store');
        $count = $ledger->rebuild(is_numeric($store) ? (int) $store : null);

        $this->info("{$count} lançamento(s) novo(s) no razão.");

        return self::SUCCESS;
    }
}
