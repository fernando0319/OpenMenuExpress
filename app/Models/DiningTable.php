<?php

namespace App\Models;

use App\Enums\TabStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property int|null $seats
 * @property int $sort_order
 * @property bool $is_active
 * @property int|null $joined_tab_id Open tab of another table this one was joined to.
 */
#[Fillable(['store_id', 'name', 'seats', 'sort_order', 'is_active'])]
class DiningTable extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seats' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
     * @return HasMany<Tab, $this>
     */
    public function tabs(): HasMany
    {
        return $this->hasMany(Tab::class);
    }

    /**
     * The tab currently using the table, if any.
     *
     * @return HasOne<Tab, $this>
     */
    public function activeTab(): HasOne
    {
        return $this->hasOne(Tab::class)->whereIn('status', TabStatus::active())->latestOfMany();
    }

    /**
     * The other table's tab this table is part of, while they are joined.
     *
     * @return BelongsTo<Tab, $this>
     */
    public function joinedTab(): BelongsTo
    {
        return $this->belongsTo(Tab::class, 'joined_tab_id');
    }

    /**
     * Neither with its own tab nor joined to another table's.
     */
    public function isFree(): bool
    {
        return $this->joined_tab_id === null && ! $this->activeTab()->exists();
    }

    /**
     * @param  Builder<DiningTable>  $query
     */
    public function scopeFree(Builder $query): void
    {
        $query->whereNull('joined_tab_id')
            ->whereDoesntHave('tabs', fn ($tabs) => $tabs->whereIn('status', TabStatus::active()));
    }
}
