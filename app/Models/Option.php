<?php

namespace App\Models;

use Database\Factories\OptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'option_group_id', 'name', 'description', 'price_cents', 'is_active', 'track_stock', 'stock_quantity', 'sort_order',
])]
class Option extends Model
{
    /** @use HasFactory<OptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_active' => 'boolean',
            'track_stock' => 'boolean',
            'stock_quantity' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<OptionGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(OptionGroup::class, 'option_group_id');
    }
}
