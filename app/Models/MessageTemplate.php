<?php

namespace App\Models;

use App\Enums\MessageEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's version of an automatic message (the default lives in MessageEvent).
 *
 * @property int $id
 * @property int $store_id
 * @property MessageEvent $event
 * @property string $body
 * @property bool $is_enabled
 */
#[Fillable(['store_id', 'event', 'body', 'is_enabled'])]
class MessageTemplate extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => MessageEvent::class,
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
