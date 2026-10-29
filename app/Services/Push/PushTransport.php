<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use Illuminate\Support\Collection;

/**
 * Hands encrypted messages to the browsers' push services.
 */
interface PushTransport
{
    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<int> Ids of subscriptions the push service no longer knows (to delete).
     */
    public function send(Collection $subscriptions, string $payload): array;
}
