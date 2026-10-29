<?php

namespace App\Services\Orders;

use App\Enums\Fulfillment;
use App\Enums\PaymentMethod;
use App\Events\OrderEdited;
use App\Models\Order;
use App\Models\OrderChange;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Product;
use App\Models\User;
use App\Services\Cash\CashRegister;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes an order after it was placed, instead of cancelling it and placing another.
 *
 * Items already in the order keep the price they were sold for (only quantity and notes change);
 * new items are priced by the catalog like any sale. Stock follows the difference, the payment
 * already received is compared with the new total, and every edit is kept in the history.
 *
 * @phpstan-import-type ItemInput from CartPricer
 * @phpstan-import-type AddressInput from CustomerBook
 *
 * @phpstan-type EditItem array{id?: int|null, product_id?: int|null, quantity: int, notes?: string|null, options?: list<array{option_id: int, quantity?: int|null}>, pizza?: array{flavor_ids: list<int>, crust_id: int|null}|null}
 * @phpstan-type EditInput array{
 *     items: list<EditItem>,
 *     customer: array{name: string|null, phone: string|null},
 *     address: AddressInput,
 *     delivery_fee_cents: int|null,
 *     discount_cents: int,
 *     payment_method: string|null,
 *     change_for_cents: int|null,
 *     notes: string|null,
 *     reason: string|null,
 *     notify_customer: bool,
 *     refund_difference: bool
 * }
 */
final class EditOrder
{
    public function __construct(
        private readonly CartPricer $pricer,
        private readonly CustomerBook $customers,
        private readonly CashRegister $register,
    ) {}

    /**
     * @param  EditInput  $input
     */
    public function handle(Order $order, array $input, User $by): OrderChange
    {
        $store = $order->store;

        /** @var list<ItemInput> $newItems */
        $newItems = [];
        /** @var list<int> $formIndex position of each new item in the form, for the error messages */
        $formIndex = [];

        foreach ($input['items'] as $index => $item) {
            if (($item['id'] ?? null) === null) {
                $formIndex[] = $index;
                $newItems[] = [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                    'options' => $item['options'] ?? [],
                    'pizza' => $item['pizza'] ?? null,
                ];
            }
        }

        // Priced outside the transaction, like a sale: the catalog rules and current prices.
        try {
            $newLines = $newItems === [] ? [] : $this->pricer->price($store, $newItems);
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $key = (string) preg_replace_callback('/^items\.(\d+)/', fn (array $match) => 'items.'.($formIndex[(int) $match[1]] ?? $match[1]), $key);
                $errors[$key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }

        [$change, $notify] = DB::transaction(function () use ($order, $input, $by, $newLines): array {
            $order = Order::query()->with('items.options')->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $order->isEditable()) {
                $this->fail('items', 'Este pedido já foi finalizado e não pode mais ser alterado.');
            }

            $isTab = $order->tab_id !== null;
            $isDelivery = $order->fulfillment === Fulfillment::Delivery;
            $before = $this->snapshot($order);
            $itemChanges = [];

            // Existing items: kept (maybe with a new quantity or note) or removed.
            $kept = [];

            foreach ($input['items'] as $index => $item) {
                if (($item['id'] ?? null) !== null) {
                    $kept[(int) $item['id']] = ['index' => $index, 'quantity' => $item['quantity'], 'notes' => $this->clean($item['notes'] ?? null)];
                }
            }

            if (array_diff(array_keys($kept), $order->items->pluck('id')->all()) !== []) {
                $this->fail('items', 'Um dos itens não pertence a este pedido. Recarregue a página.');
            }

            if ($kept === [] && $newLines === []) {
                $this->fail('items', 'O pedido precisa de pelo menos um item. Para desistir de tudo, cancele o pedido.');
            }

            $stockDelta = [];

            foreach ($order->items as $item) {
                $options = $this->optionLabels($item);

                if (! isset($kept[$item->id])) {
                    $itemChanges[] = $this->itemChange('remove', $item->quantity, $item->product_name, $options, $item->notes);
                    $this->track($stockDelta, $item->product_id, -$item->quantity);
                    $item->delete();

                    continue;
                }

                $quantity = $kept[$item->id]['quantity'];
                $notes = $kept[$item->id]['notes'];

                if ($quantity !== $item->quantity) {
                    $diff = $quantity - $item->quantity;
                    $itemChanges[] = $this->itemChange($diff > 0 ? 'add' : 'remove', abs($diff), $item->product_name, $options, null);
                    $this->track($stockDelta, $item->product_id, $diff);
                }

                if ($notes !== $item->notes) {
                    $itemChanges[] = $this->itemChange('note', $quantity, $item->product_name, $options, $notes ?? 'sem observação');
                }

                $item->update([
                    'quantity' => $quantity,
                    'notes' => $notes,
                    'total_cents' => ($item->unit_price_cents + $item->options_price_cents) * $quantity,
                ]);
            }

            foreach ($newLines as $line) {
                $item = $order->items()->create([
                    'product_id' => $line->product->id,
                    'product_name' => $line->product->name,
                    'unit_price_cents' => $line->price->unitBaseCents,
                    'options_price_cents' => $line->price->optionsPerUnitCents,
                    'quantity' => $line->price->quantity,
                    'total_cents' => $line->price->totalCents(),
                    'notes' => $line->notes,
                ]);

                foreach ($line->options as $chosen) {
                    $item->options()->create($chosen);
                }

                $itemChanges[] = $this->itemChange('add', $line->price->quantity, $line->product->name, $this->optionLabels($item->load('options')), $line->notes);
                $this->track($stockDelta, $line->product->id, $line->price->quantity);
            }

            $this->applyStock($stockDelta);

            // Totals: what was sold stays at its price, the rest follows the form.
            $subtotal = (int) $order->items()->sum('total_cents');
            $deliveryFee = $isDelivery ? ($input['delivery_fee_cents'] ?? $order->delivery_fee_cents) : 0;
            $discount = $input['discount_cents'];

            if ($discount > $subtotal + $deliveryFee) {
                $this->fail('discount', 'O desconto não pode ser maior que o total do pedido.');
            }

            $total = $subtotal + $deliveryFee - $discount;
            $attributes = [
                'subtotal_cents' => $subtotal,
                'delivery_fee_cents' => $deliveryFee,
                'discount_cents' => $discount,
                'total_cents' => $total,
                'notes' => $this->clean($input['notes']),
            ];

            if (! $isTab) {
                $attributes += $this->customerAttributes($order, $input, $isDelivery);
            }

            $received = $isTab ? 0 : $this->register->receivedCents($order);

            if (! $isTab && $received === 0) {
                $attributes += $this->declaredPayment($order, $input, $total);
            }

            $order->update($attributes);

            // Money already received against the new total.
            $refunded = 0;

            if ($received > 0) {
                if ($total > $received) {
                    $order->update(['paid_at' => null]);
                } else {
                    $order->update(['paid_at' => $order->paid_at ?? now()]);
                    $excess = $received - $total;

                    if ($excess > 0 && $input['refund_difference'] && $this->register->refundPart($order, $excess, $by)) {
                        $refunded = $excess;
                    }
                }
            }

            $fields = $this->fieldChanges($before, $this->snapshot($order->refresh()));

            if ($itemChanges === [] && $fields === []) {
                $this->fail('items', 'Nada foi alterado.');
            }

            $change = $order->changes()->create([
                'store_id' => $order->store_id,
                'user_id' => $by->id,
                'reason' => $this->clean($input['reason']),
                'items' => $itemChanges,
                'fields' => $fields,
                'total_before_cents' => $before['total'],
                'total_after_cents' => $total,
                'refunded_cents' => $refunded,
                'created_at' => now(),
            ]);

            return [$change, $input['notify_customer'] && ! $isTab && $order->customer_phone !== null];
        });

        $order->refresh();
        OrderEdited::dispatch($order, $change, $notify);

        return $change;
    }

    /**
     * @param  EditInput  $input
     * @return array<string, mixed>
     */
    private function customerAttributes(Order $order, array $input, bool $isDelivery): array
    {
        $name = trim((string) $input['customer']['name']);
        $phone = $input['customer']['phone'];
        $address = $isDelivery ? $input['address'] : [];

        if ($isDelivery && ($name === '' || $phone === null)) {
            $this->fail('customer.name', 'Para entrega, informe o nome e o WhatsApp do cliente.');
        }

        if ($isDelivery && (($address['street'] ?? null) === null || ($address['number'] ?? null) === null)) {
            $this->fail('address.street', 'Informe a rua e o número para a entrega.');
        }

        $customer = $phone === null ? null : $this->customers->remember($order->store, $name !== '' ? $name : PlacePosOrder::WALK_IN_NAME, $phone, $isDelivery ? $address : null);

        $attributes = [
            'customer_id' => $customer?->id,
            'customer_name' => $name !== '' ? $name : $order->customer_name,
            'customer_phone' => $phone,
        ];

        if ($isDelivery) {
            $attributes += [
                'delivery_zip' => $address['zip'] ?? null,
                'delivery_street' => $address['street'] ?? null,
                'delivery_number' => $address['number'] ?? null,
                'delivery_district' => $address['district'] ?? null,
                'delivery_complement' => $address['complement'] ?? null,
                'delivery_reference' => $address['reference'] ?? null,
            ];
        }

        return $attributes;
    }

    /**
     * Payment declared for later (on delivery or at the counter), while nothing was received.
     *
     * @param  EditInput  $input
     * @return array<string, mixed>
     */
    private function declaredPayment(Order $order, array $input, int $total): array
    {
        $method = $input['payment_method'] !== null ? PaymentMethod::tryFrom($input['payment_method']) : null;

        if ($method === null) {
            return [];
        }

        $changeFor = $method === PaymentMethod::Cash ? $input['change_for_cents'] : null;

        if ($changeFor !== null && $changeFor < $total) {
            $this->fail('change_for', 'O troco deve ser para um valor maior ou igual ao novo total de R$ '.Money::toInput($total).'.');
        }

        return ['payment_method' => $method->value, 'change_for_cents' => $changeFor];
    }

    /**
     * Gives back or takes the difference of each tracked product, locking the rows.
     *
     * @param  array<int, int>  $delta  product id => units more (+) or less (−) than before
     */
    private function applyStock(array $delta): void
    {
        ksort($delta);

        foreach ($delta as $productId => $quantity) {
            if ($quantity === 0) {
                continue;
            }

            $product = Product::query()->withTrashed()->whereKey($productId)->lockForUpdate()->first();

            if ($product === null || ! $product->track_stock) {
                continue;
            }

            if ($quantity > 0 && ($product->stock_quantity ?? 0) < $quantity) {
                $this->fail('items', "Só restam {$product->stock_quantity} unidade(s) de {$product->name}.");
            }

            $product->decrement('stock_quantity', $quantity);
        }
    }

    /**
     * @param  array<int, int>  $delta
     */
    private function track(array &$delta, ?int $productId, int $quantity): void
    {
        if ($productId !== null) {
            $delta[$productId] = ($delta[$productId] ?? 0) + $quantity;
        }
    }

    /**
     * @return array<string, string|int|null>
     */
    private function snapshot(Order $order): array
    {
        return [
            'total' => $order->total_cents,
            'customer' => trim($order->customer_name.' '.($order->customer_phone ?? '')),
            'address' => $order->delivery_street === null ? null : trim("{$order->delivery_street}, {$order->delivery_number} {$order->delivery_complement} {$order->delivery_district} {$order->delivery_reference}"),
            'fee' => $order->delivery_fee_cents,
            'discount' => $order->discount_cents,
            'payment' => $order->paymentLabel().($order->change_for_cents !== null ? ' (troco para R$ '.Money::toInput($order->change_for_cents).')' : ''),
            'notes' => $order->notes,
        ];
    }

    /**
     * @param  array<string, string|int|null>  $before
     * @param  array<string, string|int|null>  $after
     * @return list<string>
     */
    private function fieldChanges(array $before, array $after): array
    {
        $money = fn (string|int|null $cents) => 'R$ '.Money::toInput((int) $cents);
        $lines = [];

        if ($before['customer'] !== $after['customer']) {
            $lines[] = "Cliente: {$after['customer']}";
        }

        if ($before['address'] !== $after['address']) {
            $lines[] = "Endereço: {$after['address']}";
        }

        if ($before['fee'] !== $after['fee']) {
            $lines[] = "Taxa de entrega: {$money($before['fee'])} → {$money($after['fee'])}";
        }

        if ($before['discount'] !== $after['discount']) {
            $lines[] = "Desconto: {$money($before['discount'])} → {$money($after['discount'])}";
        }

        if ($before['payment'] !== $after['payment']) {
            $lines[] = "Pagamento: {$after['payment']}";
        }

        if ($before['notes'] !== $after['notes']) {
            $lines[] = 'Observação do pedido: '.($after['notes'] ?? 'removida');
        }

        return $lines;
    }

    /**
     * @param  list<string>  $options
     * @return array{kind: 'add'|'remove'|'note', quantity: int, name: string, options: list<string>, notes: string|null}
     */
    private function itemChange(string $kind, int $quantity, string $name, array $options, ?string $notes): array
    {
        /** @var 'add'|'remove'|'note' $kind */
        return ['kind' => $kind, 'quantity' => $quantity, 'name' => $name, 'options' => $options, 'notes' => $notes];
    }

    /**
     * @return list<string>
     */
    private function optionLabels(OrderItem $item): array
    {
        return array_values($item->options->map(
            fn (OrderItemOption $option) => ($option->quantity > 1 ? "{$option->quantity}x " : '').$option->option_name,
        )->all());
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
