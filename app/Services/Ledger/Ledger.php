<?php

namespace App\Services\Ledger;

use App\Enums\Asset;
use App\Enums\CashMovementType;
use App\Models\CashMovement;
use App\Models\LedgerEntry;
use App\Models\OnlinePayment;
use App\Payments\OnlinePayments;
use App\Payments\PaymentException;
use App\Payments\ReportsReceivedAsset;
use Illuminate\Support\Facades\Log;

/**
 * The store's record of money in and out, whatever it came in: a sale in cash, a Pix on delivery,
 * a card, bitcoin paid on the menu. Each entry keeps the asset and quantity that moved and its
 * value in reais at that moment, for the reports, the finance module and the tax return.
 *
 * Fed after the fact by the cash register and by online payments; recording the same event again
 * changes nothing, so the whole history can be rebuilt at any time.
 */
final class Ledger
{
    /**
     * Sales and refunds of the cash register. Supply and withdrawal only move money inside the
     * store (drawer ↔ safe), so they are not income.
     */
    public function recordCashMovement(CashMovement $movement): ?LedgerEntry
    {
        if (! in_array($movement->type, [CashMovementType::Sale, CashMovementType::Refund], true)
            || $movement->payment_method === null
            || $movement->amount_cents === 0) {
            return null;
        }

        $signed = $movement->signedCents();

        return $this->write([
            'store_id' => $movement->store_id,
            'order_id' => $movement->order_id,
            'occurred_at' => $movement->created_at ?? now(),
            'asset' => Asset::Brl,
            'units' => $signed,
            'brl_cents' => $signed,
            'rate' => null,
            'method' => $movement->payment_method->value,
            'source_type' => LedgerEntry::FROM_CASH,
            'source_id' => $movement->id,
            'kind' => $signed > 0 ? LedgerEntry::IN : LedgerEntry::OUT,
            'description' => $movement->description,
        ]);
    }

    /**
     * A charge paid on the menu comes in when paid, and goes out again if refunded.
     */
    public function recordOnlinePayment(OnlinePayment $payment): void
    {
        if ($payment->paid_at === null) {
            return;
        }

        $received = $this->write([
            'store_id' => $payment->store_id,
            'order_id' => $payment->order_id,
            'occurred_at' => $payment->paid_at,
            ...$this->asset($payment),
            'brl_cents' => $payment->amount_cents,
            'method' => $payment->method->value,
            'source_type' => LedgerEntry::FROM_ONLINE,
            'source_id' => $payment->id,
            'kind' => LedgerEntry::IN,
            'description' => $payment->provider->label(),
        ]);

        if ($payment->status === OnlinePayment::REFUNDED) {
            $this->write([
                'store_id' => $payment->store_id,
                'order_id' => $payment->order_id,
                'occurred_at' => $payment->refunded_at ?? now(),
                'asset' => $received->asset,
                'units' => $received->units === null ? null : -$received->units,
                'rate' => $received->rate,
                'brl_cents' => -$payment->amount_cents,
                'method' => $payment->method->value,
                'source_type' => LedgerEntry::FROM_ONLINE,
                'source_id' => $payment->id,
                'kind' => LedgerEntry::OUT,
                'description' => 'Estorno · '.$payment->provider->label(),
            ]);
        }
    }

    /**
     * Records again everything the cash register and online payments hold. Safe to repeat.
     *
     * @return int new entries
     */
    public function rebuild(?int $storeId = null): int
    {
        $before = LedgerEntry::query()->when($storeId, fn ($query) => $query->where('store_id', $storeId))->count();

        CashMovement::query()
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->whereIn('type', [CashMovementType::Sale, CashMovementType::Refund])
            ->chunkById(500, fn ($movements) => $movements->each(fn (CashMovement $movement) => $this->recordCashMovement($movement)));

        OnlinePayment::query()
            ->with('account')
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->whereNotNull('paid_at')
            ->chunkById(200, fn ($payments) => $payments->each(fn (OnlinePayment $payment) => $this->recordOnlinePayment($payment)));

        return LedgerEntry::query()->when($storeId, fn ($query) => $query->where('store_id', $storeId))->count() - $before;
    }

    /**
     * Reais unless the gateway tells what else arrived (satoshis of a bitcoin invoice). If it
     * cannot tell now, the quantity stays unknown and a later rebuild fills it in.
     *
     * @return array{asset: Asset, units: int|null, rate: string|null}
     */
    private function asset(OnlinePayment $payment): array
    {
        $gateway = OnlinePayments::gateway($payment->provider);

        if (! $gateway instanceof ReportsReceivedAsset) {
            return ['asset' => Asset::Brl, 'units' => $payment->amount_cents, 'rate' => null];
        }

        $received = null;

        if ($payment->account !== null) {
            try {
                $received = $gateway->receivedAsset($payment->account, $payment);
            } catch (PaymentException $exception) {
                Log::warning('Ledger: received asset unknown', ['payment' => $payment->id, 'error' => $exception->getMessage()]);
            }
        }

        if ($received === null) {
            return ['asset' => Asset::Btc, 'units' => null, 'rate' => null];
        }

        return [
            'asset' => $received->asset,
            'units' => $received->units,
            'rate' => self::rate($payment->amount_cents, $received->units, $received->asset),
        ];
    }

    /**
     * Reais per whole unit: R$ 50,00 for 10.000 sats → "500000.00" per bitcoin.
     */
    private static function rate(int $brlCents, int $units, Asset $asset): string
    {
        // In hundredths of a real, so the division stays in integers.
        $hundredths = intdiv($brlCents * 10 ** $asset->decimals(), $units);

        return intdiv($hundredths, 100).'.'.str_pad((string) ($hundredths % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(array $attributes): LedgerEntry
    {
        $key = ['source_type' => $attributes['source_type'], 'source_id' => $attributes['source_id'], 'kind' => $attributes['kind']];
        $entry = LedgerEntry::query()->firstOrCreate($key, $attributes);

        // Recorded before the gateway could tell the quantity: complete it now.
        if ($entry->units === null && $attributes['units'] !== null) {
            $entry->update(['units' => $attributes['units'], 'rate' => $attributes['rate']]);
        }

        return $entry;
    }
}
