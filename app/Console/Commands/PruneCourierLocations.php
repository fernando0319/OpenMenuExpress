<?php

namespace App\Console\Commands;

use App\Models\CourierLocation;
use App\Services\Delivery\DeliveryTracking;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Couriers' positions are personal data: kept only for a few days after the delivery.
 */
#[Signature('app:prune-courier-locations')]
#[Description('Delete courier positions older than the retention period')]
class PruneCourierLocations extends Command
{
    public function handle(DeliveryTracking $tracking): int
    {
        $deleted = $tracking->prune();

        $this->info("{$deleted} posição(ões) com mais de ".CourierLocation::RETENTION_DAYS.' dias apagada(s).');

        return self::SUCCESS;
    }
}
