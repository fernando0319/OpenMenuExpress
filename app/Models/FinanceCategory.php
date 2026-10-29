<?php

namespace App\Models;

use App\Enums\FinanceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property FinanceType $type
 * @property string $name
 * @property bool $is_active
 */
#[Fillable(['store_id', 'type', 'name', 'is_active'])]
class FinanceCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinanceType::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FinanceTitle, $this>
     */
    public function titles(): HasMany
    {
        return $this->hasMany(FinanceTitle::class, 'category_id');
    }
}
