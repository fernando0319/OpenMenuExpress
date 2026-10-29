<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Prints a new pair of VAPID keys for the .env. Run once per installation: new keys
 * invalidate the notifications every phone accepted.
 */
#[Signature('app:vapid-keys')]
#[Description('Generate the VAPID keys used to send push notifications')]
class GenerateVapidKeys extends Command
{
    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);

        return self::SUCCESS;
    }
}
