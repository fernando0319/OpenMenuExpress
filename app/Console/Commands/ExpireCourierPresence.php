<?php

namespace App\Console\Commands;

use App\Jobs\SendPushNotification;
use App\Models\NetworkCourier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Couriers online whose phone stopped sending the position long ago (forgot to go offline,
 * phone off) leave the network, so stores are not promised couriers who are not there.
 */
#[Signature('app:expire-courier-presence')]
#[Description('Take offline the network couriers without a recent position')]
class ExpireCourierPresence extends Command
{
    public function handle(): int
    {
        $stale = NetworkCourier::query()
            ->where('is_online', true)
            ->where(fn ($query) => $query
                ->whereNull('located_at')
                ->orWhere('located_at', '<', now()->subMinutes(NetworkCourier::LOCATION_FRESH_MINUTES)))
            ->get();

        foreach ($stale as $courier) {
            if ($courier->activeDelivery() !== null) {
                continue; // never in the middle of a run
            }

            $courier->update(['is_online' => false]);

            SendPushNotification::dispatch(
                [$courier->user_id],
                'Você ficou offline',
                'Sua localização não chega há um tempo. Abra o app e fique online de novo para receber corridas.',
                '/entregador',
                'presence',
            );
        }

        $this->info($stale->count().' entregador(es) sem posição recente.');

        return self::SUCCESS;
    }
}
