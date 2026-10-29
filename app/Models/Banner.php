<?php

namespace App\Models;

use App\Enums\BannerLinkType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $store_id
 * @property string $title
 * @property string $image_path
 * @property BannerLinkType $link_type
 * @property string|null $link_target
 * @property int $sort_order
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $is_active
 */
#[Fillable(['store_id', 'title', 'image_path', 'link_type', 'link_target', 'sort_order', 'starts_at', 'ends_at', 'is_active'])]
class Banner extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'link_type' => BannerLinkType::class,
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image_path);
    }

    /**
     * Active and inside its display period right now.
     *
     * @param  Builder<Banner>  $query
     */
    public function scopeShowing(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
