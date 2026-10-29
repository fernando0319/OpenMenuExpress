<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A stuffed crust ("Borda Catupiry"), with one price per pizza size.
 *
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string|null $description
 * @property array<int, int> $prices product id => cents
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable(['store_id', 'name', 'description', 'prices', 'sort_order', 'is_active'])]
class PizzaCrust extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prices' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function priceFor(int $productId): ?int
    {
        return $this->prices[$productId] ?? null;
    }
}
