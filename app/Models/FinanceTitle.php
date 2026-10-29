<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Enums\FinanceFrequency;
use App\Enums\FinancePaymentMethod;
use App\Enums\FinanceType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payable or receivable document (a purchase, a bill, a sale on credit) and its installments.
 *
 * @property int $id
 * @property int $store_id
 * @property FinanceType $type
 * @property string $description
 * @property int|null $supplier_id
 * @property string|null $counterparty
 * @property int|null $category_id
 * @property string|null $document_number
 * @property CarbonImmutable $issued_on
 * @property int $total_cents
 * @property int $installments_count
 * @property FinanceFrequency $frequency
 * @property bool $auto_settle
 * @property FinancePaymentMethod|null $auto_settle_method
 * @property string|null $notes
 * @property CarbonImmutable|null $canceled_at
 * @property int|null $created_by
 * @property-read Store $store
 * @property-read Supplier|null $supplier
 * @property-read FinanceCategory|null $category
 */
#[Fillable([
    'store_id', 'type', 'description', 'supplier_id', 'counterparty', 'category_id', 'document_number', 'issued_on',
    'total_cents', 'installments_count', 'frequency', 'auto_settle', 'auto_settle_method', 'notes', 'canceled_at', 'created_by',
])]
class FinanceTitle extends Model
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
            'frequency' => FinanceFrequency::class,
            'auto_settle_method' => FinancePaymentMethod::class,
            'issued_on' => DateOnly::class,
            'canceled_at' => 'datetime',
            'total_cents' => 'integer',
            'installments_count' => 'integer',
            'auto_settle' => 'boolean',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<FinanceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    /**
     * @return HasMany<FinanceInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(FinanceInstallment::class, 'title_id')->orderBy('number');
    }

    /**
     * The supplier for payables, the free-text party otherwise.
     */
    public function partyName(): ?string
    {
        return $this->supplier->name ?? $this->counterparty;
    }
}
