<?php

namespace App\Models;

use App\Enums\Module;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property int $price_cents
 * @property string $interval
 * @property int|null $free_up_to_cents
 * @property int|null $percent_bps percentage in basis points (250 = 2,5%), for plans charged by use
 * @property string $percent_base excess (only what passes the free limit) | total (all sales, once above it)
 * @property int|null $fee_min_cents
 * @property int|null $fee_max_cents
 * @property list<string> $modules
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable(['name', 'price_cents', 'interval', 'free_up_to_cents', 'percent_bps', 'percent_base', 'fee_min_cents', 'fee_max_cents', 'modules', 'is_active', 'sort_order'])]
class Plan extends Model
{
    public const BASE_EXCESS = 'excess';

    public const BASE_TOTAL = 'total';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'free_up_to_cents' => 'integer',
            'percent_bps' => 'integer',
            'fee_min_cents' => 'integer',
            'fee_max_cents' => 'integer',
            'modules' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return list<Module>
     */
    public function moduleList(): array
    {
        // Modules removed from the system may still be listed in old plans: skip them.
        return array_values(array_filter(array_map(
            fn (string $value) => Module::tryFrom($value),
            $this->modules,
        )));
    }

    /**
     * Free while the store sells up to free_up_to_cents in a calendar month; above it, a paid
     * plan is needed.
     */
    public function isFree(): bool
    {
        return $this->free_up_to_cents !== null && ! $this->isUsage();
    }

    /**
     * Charged each month by a percentage of the online sales (free below free_up_to_cents).
     */
    public function isUsage(): bool
    {
        return $this->percent_bps !== null;
    }

    /**
     * What a month with these online sales costs on this plan. Below the free limit, nothing;
     * above it, the fixed part plus the percentage (of the excess or of the total), then the
     * minimum and the cap.
     */
    public function usageFee(int $salesCents): int
    {
        $limit = $this->free_up_to_cents;

        if ($limit !== null && $salesCents <= $limit) {
            return 0;
        }

        $base = $limit !== null && $this->percent_base === self::BASE_EXCESS ? $salesCents - $limit : $salesCents;
        $amount = $this->price_cents + intdiv($base * (int) $this->percent_bps + 5000, 10000);

        if ($this->fee_min_cents !== null) {
            $amount = max($amount, $this->fee_min_cents);
        }

        if ($this->fee_max_cents !== null) {
            $amount = min($amount, $this->fee_max_cents);
        }

        return $amount;
    }

    /**
     * "Grátis até R$ 3.000,00 em vendas online; acima, 2% do que passar (mín. R$ 19,00, máx. R$ 299,00)".
     */
    public function usageSummary(): ?string
    {
        if (! $this->isUsage()) {
            return null;
        }

        $percent = rtrim(rtrim(number_format((int) $this->percent_bps / 100, 2, ',', ''), '0'), ',').'%';
        $money = fn (int $cents) => 'R$ '.number_format($cents / 100, 2, ',', '.');
        $charge = $this->free_up_to_cents !== null && $this->percent_base === self::BASE_EXCESS
            ? "{$percent} do que passar"
            : "{$percent} das vendas online";

        if ($this->price_cents > 0) {
            $charge = "{$money($this->price_cents)} + {$charge}";
        }

        $limits = array_filter([
            $this->fee_min_cents !== null ? 'mín. '.$money($this->fee_min_cents) : null,
            $this->fee_max_cents !== null ? 'máx. '.$money($this->fee_max_cents) : null,
        ]);
        $limits = $limits === [] ? '' : ' ('.implode(', ', $limits).')';

        return $this->free_up_to_cents !== null
            ? "Grátis até {$money($this->free_up_to_cents)} em vendas online no mês; acima, {$charge}{$limits}."
            : "{$charge} por mês{$limits}.";
    }

    /**
     * Percentage as typed in the admin form ("2,5").
     */
    public function percentInput(): string
    {
        return $this->percent_bps === null ? '' : Money::toInput($this->percent_bps);
    }

    /**
     * The plan stores fall back to when no paid time or trial is left: a free plan, or a plan
     * charged by use.
     */
    public static function fallback(): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereNotNull('free_up_to_cents')->orWhereNotNull('percent_bps'))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    public function intervalLabel(): string
    {
        return $this->interval === 'year' ? 'por ano' : 'por mês';
    }

    /**
     * @return HasMany<Store, $this>
     */
    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    /**
     * @return HasMany<Voucher, $this>
     */
    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }
}
