<?php

namespace App\Models;

use App\Enums\PricingMode;
use Database\Factories\OptionGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property int $min_choices
 * @property int $max_choices
 * @property PricingMode $pricing_mode
 * @property bool $allows_quantity
 * @property bool $is_active
 */
#[Fillable(['store_id', 'name', 'min_choices', 'max_choices', 'pricing_mode', 'allows_quantity', 'is_active'])]
class OptionGroup extends Model
{
    /** @use HasFactory<OptionGroupFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_choices' => 'integer',
            'max_choices' => 'integer',
            'pricing_mode' => PricingMode::class,
            'allows_quantity' => 'boolean',
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
     * @return HasMany<Option, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(Option::class)->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Product, $this, ProductOptionGroup>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->using(ProductOptionGroup::class)
            ->withPivot(['min_choices', 'max_choices', 'sort_order'])
            ->withTimestamps();
    }
}
