<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'product_id', 'product_name', 'unit_price_cents', 'options_price_cents', 'quantity', 'total_cents',
    'notes',
])]
class OrderItem extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_cents' => 'integer',
            'options_price_cents' => 'integer',
            'quantity' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return HasMany<OrderItemOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(OrderItemOption::class);
    }
}
