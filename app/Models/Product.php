<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $store_id
 * @property int $category_id
 * @property string $name
 * @property string|null $description
 * @property string|null $sku
 * @property string|null $image_path
 * @property int $price_cents
 * @property int|null $promotional_price_cents
 * @property bool $is_featured
 * @property bool $is_active
 * @property bool $track_stock
 * @property int|null $stock_quantity
 * @property int $sort_order
 * @property string $kind standard | pizza
 * @property int|null $pizza_slices
 * @property int|null $pizza_diameter_cm
 * @property int|null $pizza_max_flavors
 */
#[Fillable([
    'store_id', 'category_id', 'kind', 'pizza_slices', 'pizza_diameter_cm', 'pizza_max_flavors', 'name', 'description', 'image_path', 'sku', 'price_cents', 'promotional_price_cents',
    'is_featured', 'is_active', 'track_stock', 'stock_quantity', 'sort_order',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'promotional_price_cents' => 'integer',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'sort_order' => 'integer',
            'pizza_slices' => 'integer',
            'pizza_diameter_cm' => 'integer',
            'pizza_max_flavors' => 'integer',
        ];
    }

    public const KIND_PIZZA = 'pizza';

    /**
     * A pizza size: flavors and crusts are chosen in the pizza builder, priced by the store's tables.
     */
    public function isPizza(): bool
    {
        return $this->kind === self::KIND_PIZZA;
    }

    /**
     * Price charged per unit before options: the promotional price when it is lower.
     */
    public function effectivePriceCents(): int
    {
        $promo = $this->promotional_price_cents;

        return $promo !== null && $promo < $this->price_cents ? $promo : $this->price_cents;
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsToMany<OptionGroup, $this, ProductOptionGroup>
     */
    public function optionGroups(): BelongsToMany
    {
        return $this->belongsToMany(OptionGroup::class)
            ->using(ProductOptionGroup::class)
            ->withPivot(['min_choices', 'max_choices', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }
}
