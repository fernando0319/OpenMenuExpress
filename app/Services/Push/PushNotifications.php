<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Notifications on the phone, even with the screen off or the app closed (Web Push).
 */
final class PushNotifications
{
    public function __construct(private readonly PushTransport $transport) {}

    public static function publicKey(): ?string
    {
        $key = config('webpush.public_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @param  array{endpoint: string, public_key: string, auth_token: string, content_encoding?: string|null}  $data
     */
    public function subscribe(User $user, array $data, ?string $userAgent): PushSubscription
    {
        // The same browser may change hands (another person signs in): it follows the current user.
        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $user->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['public_key'],
                'auth_token' => $data['auth_token'],
                'content_encoding' => $data['content_encoding'] ?? 'aes128gcm',
                'user_agent' => $userAgent !== null ? Str::limit($userAgent, 250, '') : null,
            ],
        );
    }

    /**
     * @param  list<int>  $userIds
     * @param  string  $tag  Notifications with the same tag replace each other.
     */
    public function toUsers(array $userIds, string $title, string $body, string $url, string $tag): void
    {
        $subscriptions = PushSubscription::query()->whereIn('user_id', $userIds)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = (string) json_encode(['title' => $title, 'body' => $body, 'url' => $url, 'tag' => $tag], JSON_UNESCAPED_UNICODE);

        $expired = $this->transport->send($subscriptions, $payload);

        if ($expired !== []) {
            PushSubscription::query()->whereKey($expired)->delete();
        }
    }
}
