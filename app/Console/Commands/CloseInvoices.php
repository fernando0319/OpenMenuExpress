<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\Store;
use App\Services\Billing\Subscription;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes last month into an invoice for every store on a plan charged by use, even the ones
 * nobody opened this month (the panel also closes it on the first visit).
 */
#[Signature('app:close-invoices')]
#[Description('Close last month into invoices for stores on plans charged by use')]
class CloseInvoices extends Command
{
    public function handle(): int
    {
        if (! config('billing.enabled')) {
            $this->info('Cobrança desligada nesta instalação.');

            return self::SUCCESS;
        }

        $before = Invoice::query()->count();

        Store::query()->where('is_complimentary', false)->with('plan')->chunkById(100, function ($stores): void {
            foreach ($stores as $store) {
                Subscription::for($store);
            }
        });

        $this->info((Invoice::query()->count() - $before).' fatura(s) fechada(s).');

        return self::SUCCESS;
    }
}
