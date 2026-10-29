<?php

namespace App\Models;

use App\Enums\StoreRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * A user's membership (and role) in a store.
 *
 * @property int $id
 * @property int $store_id
 * @property int $user_id
 * @property StoreRole $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StoreMember extends Pivot
{
    protected $table = 'store_user';

    public $incrementing = true;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => StoreRole::class,
        ];
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
