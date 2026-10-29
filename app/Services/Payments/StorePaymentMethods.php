<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethod;
use App\Models\Store;
use App\Models\StorePaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which payment methods a store accepts, and in which order.
 *
 * Today these are the methods settled outside the system (cash, PIX, cards on delivery).
 * Online payment modules (gateways) will register more methods here; the store turns them
 * on in the same screen.
 */
final class StorePaymentMethods
{
    /**
     * Every method the platform offers, in the default order.
     *
     * @return list<PaymentMethod>
     */
    public function available(): array
    {
        return PaymentMethod::cases();
    }

    /**
     * The methods this store accepts. A store that never saved the setting accepts all of them.
     *
     * @return list<PaymentMethod>
     */
    public function accepted(Store $store): array
    {
        return array_values(array_map(
            fn (array $row) => $row['method'],
            array_filter($this->settings($store), fn (array $row) => $row['active']),
        ));
    }

    public function accepts(Store $store, PaymentMethod $method): bool
    {
        return in_array($method, $this->accepted($store), true);
    }

    /**
     * Every available method with the store's choice, in the store's order.
     *
     * @return list<array{method: PaymentMethod, active: bool}>
     */
    public function settings(Store $store): array
    {
        $saved = StorePaymentMethod::query()
            ->where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->keyBy('method');

        $rows = [];

        foreach ($saved as $row) {
            $method = PaymentMethod::tryFrom($row->method);

            // A module that was removed leaves its row behind; it simply stops showing.
            if ($method !== null) {
                $rows[] = ['method' => $method, 'active' => $row->is_active];
            }
        }

        foreach ($this->available() as $method) {
            if (! $saved->has($method->value)) {
                $rows[] = ['method' => $method, 'active' => $saved->isEmpty()];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $ordered  Every method key in the order the store wants.
     * @param  list<string>  $active  Keys the store accepts.
     */
    public function save(Store $store, array $ordered, array $active): void
    {
        $known = array_map(fn (PaymentMethod $method) => $method->value, $this->available());
        $active = array_values(array_intersect($active, $known));

        if ($active === []) {
            throw ValidationException::withMessages(['methods' => 'Deixe pelo menos uma forma de pagamento ativa.']);
        }

        // Keep the given order, then anything missing from it at the end.
        $ordered = array_values(array_unique([...array_intersect($ordered, $known), ...$known]));

        DB::transaction(function () use ($store, $ordered, $active): void {
            foreach ($ordered as $position => $method) {
                StorePaymentMethod::query()->updateOrCreate(
                    ['store_id' => $store->id, 'method' => $method],
                    ['is_active' => in_array($method, $active, true), 'sort_order' => $position],
                );
            }
        });
    }

    /**
     * The accepted methods as the screens show them.
     *
     * @return list<array{value: string, label: string}>
     */
    public function options(Store $store): array
    {
        return array_map(
            fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()],
            $this->accepted($store),
        );
    }
}
