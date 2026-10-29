<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $store_id
 * @property string $name
 * @property string|null $document
 * @property string|null $contact_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $notes
 * @property bool $is_active
 */
#[Fillable(['store_id', 'name', 'document', 'contact_name', 'phone', 'email', 'notes', 'is_active'])]
class Supplier extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return HasMany<FinanceTitle, $this>
     */
    public function titles(): HasMany
    {
        return $this->hasMany(FinanceTitle::class);
    }

    /**
     * CPF (000.000.000-00) or CNPJ (00.000.000/0000-00) for display.
     */
    public function formattedDocument(): ?string
    {
        $digits = (string) $this->document;

        return match (strlen($digits)) {
            11 => substr($digits, 0, 3).'.'.substr($digits, 3, 3).'.'.substr($digits, 6, 3).'-'.substr($digits, 9, 2),
            14 => substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2),
            default => $this->document,
        };
    }
}
