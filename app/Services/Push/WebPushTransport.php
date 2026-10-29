<?php

namespace App\Services\Push;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push with VAPID (minishlink/web-push). Messages are urgent and short-lived: a run
 * offer that arrives after a few minutes is useless.
 */
final class WebPushTransport implements PushTransport
{
    private const TTL_SECONDS = 300;

    public function send(Collection $subscriptions, string $payload): array
    {
        $publicKey = config('webpush.public_key');
        $privateKey = config('webpush.private_key');

        if (! is_string($publicKey) || $publicKey === '' || ! is_string($privateKey) || $privateKey === '' || $subscriptions->isEmpty()) {
            return [];
        }

        $webPush = new WebPush(
            ['VAPID' => ['subject' => (string) config('webpush.subject'), 'publicKey' => $publicKey, 'privateKey' => $privateKey]],
            ['TTL' => self::TTL_SECONDS, 'urgency' => 'high'],
        );

        $byEndpoint = [];

        foreach ($subscriptions as $subscription) {
            $byEndpoint[$subscription->endpoint] = $subscription->id;

            $webPush->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
                'contentEncoding' => $subscription->content_encoding,
            ]), $payload);
        }

        $expired = [];

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            if ($report->isSubscriptionExpired()) {
                $expired[] = $byEndpoint[$report->getEndpoint()] ?? null;

                continue;
            }

            Log::warning('Web push failed', ['reason' => $report->getReason()]);
        }

        return array_values(array_filter($expired, fn (?int $id) => $id !== null));
    }
}
