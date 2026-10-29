<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * Links an option group to a product, with optional per-product limits.
 *
 * @property int $id
 * @property int $product_id
 * @property int $option_group_id
 * @property int|null $min_choices
 * @property int|null $max_choices
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProductOptionGroup extends Pivot
{
    protected $table = 'option_group_product';

    public $incrementing = true;

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
            'sort_order' => 'integer',
        ];
    }
}
