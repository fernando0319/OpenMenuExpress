<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every intervention made from the admin panel, kept for accountability.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $store_id
 * @property string $action
 * @property array<string, mixed>|null $details
 * @property string|null $ip
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['user_id', 'store_id', 'action', 'details', 'ip'])]
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_SUSPEND = 'store.suspend';

    public const ACTION_REACTIVATE = 'store.reactivate';

    public const ACTION_SUPPORT_START = 'support.start';

    public const ACTION_SUPPORT_STOP = 'support.stop';

    public const ACTION_SUPPORT_CHANGE = 'support.change';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function actionLabel(): string
    {
        return match ($this->action) {
            self::ACTION_SUSPEND => 'Loja suspensa',
            self::ACTION_REACTIVATE => 'Loja reativada',
            self::ACTION_SUPPORT_START => ($this->details['mode'] ?? null) === 'full' ? 'Suporte entrou (pode alterar)' : 'Suporte entrou (somente leitura)',
            self::ACTION_SUPPORT_STOP => 'Suporte saiu',
            self::ACTION_SUPPORT_CHANGE => 'Suporte alterou dados',
            'billing.extend' => 'Acesso liberado',
            'billing.plan' => 'Plano alterado',
            'billing.complimentary' => 'Cortesia ativada',
            'billing.complimentary_off' => 'Cortesia removida',
            'billing.invoice_paid' => 'Fatura marcada como paga',
            'billing.invoice_waived' => 'Fatura dispensada',
            'billing.invoice_reopened' => 'Fatura reaberta',
            default => $this->action,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Store, $this>
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
