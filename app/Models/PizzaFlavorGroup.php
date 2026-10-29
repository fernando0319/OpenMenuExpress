<?php

namespace App\Models;

use App\Enums\FlavorKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price tier of flavors ("Salgadas Tradicionais"): one price per pizza size.
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property FlavorKind $kind
 * @property array<int, int> $prices product id => cents
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['store_id', 'name', 'kind', 'prices', 'sort_order', 'is_active'])]
class PizzaFlavorGroup extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => FlavorKind::class,
            'prices' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PizzaFlavor, $this>
     */
    public function flavors(): HasMany
    {
        return $this->hasMany(PizzaFlavor::class, 'group_id')->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Price in this size, or null when the group is not sold in it.
     */
    public function priceFor(int $productId): ?int
    {
        return $this->prices[$productId] ?? null;
    }
}
