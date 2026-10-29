<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A flavor. Its price comes from its group, plus an optional extra (e.g. shrimp).
 *
 * @property int $id
 * @property int $store_id
 * @property int $group_id
 * @property string $name
 * @property string|null $description
 * @property string|null $image_path
 * @property int $extra_cents
 * @property list<int>|null $unavailable_product_ids sizes where this flavor is not offered
 * @property int $sort_order
 * @property bool $is_active
 * @property-read PizzaFlavorGroup $group
 */
#[Fillable(['store_id', 'group_id', 'name', 'description', 'image_path', 'extra_cents', 'unavailable_product_ids', 'sort_order', 'is_active'])]
class PizzaFlavor extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_cents' => 'integer',
            'unavailable_product_ids' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PizzaFlavorGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PizzaFlavorGroup::class, 'group_id');
    }

    public function isOfferedIn(int $productId): bool
    {
        return ! in_array($productId, array_map('intval', $this->unavailable_product_ids ?? []), true);
    }
}
