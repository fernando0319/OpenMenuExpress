<?php

namespace App\Jobs;

use App\Services\Push\PushNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One notification to some people's phones, sent by the queue so nobody waits on the push services.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  list<int>  $userIds
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $title,
        public readonly string $body,
        public readonly string $url,
        public readonly string $tag,
    ) {
        $this->afterCommit();
    }

    public function handle(PushNotifications $push): void
    {
        $push->toUsers($this->userIds, $this->title, $this->body, $this->url, $this->tag);
    }
}
