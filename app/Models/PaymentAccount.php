<?php

namespace App\Models;

use App\Enums\OnlinePaymentMethod;
use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A store's own account at a payment provider. The money of each sale goes straight to it.
 *
 * @property int $id
 * @property int $store_id
 * @property PaymentProvider $provider
 * @property list<string> $methods
 * @property array<string, mixed> $credentials
 * @property string|null $account_label
 * @property string $webhook_token
 * @property bool $is_active
 * @property string|null $last_error
 * @property-read Store $store
 */
#[Fillable(['store_id', 'provider', 'methods', 'credentials', 'account_label', 'webhook_token', 'is_active', 'last_error'])]
#[Hidden(['credentials'])]
class PaymentAccount extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'methods' => 'array',
            'credentials' => 'encrypted:array',
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

    public function takes(OnlinePaymentMethod $method): bool
    {
        return $this->is_active && in_array($method->value, $this->methods, true);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
