<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser on a phone that accepted notifications. The push service (Google, Apple,
 * Mozilla) delivers to the endpoint; the keys encrypt the message for that browser only.
 *
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 * @property string|null $user_agent
 */
#[Fillable(['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding', 'user_agent'])]
#[Hidden(['public_key', 'auth_token'])]
class PushSubscription extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
