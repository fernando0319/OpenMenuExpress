<?php

namespace App\Services\Orders;

use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Events\OrderPlaced;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Cash\CashRegister;
use App\Services\Cash\Payment;
use App\Services\Cash\Settlement;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A sale at the counter. Same catalog rules and prices as the menu, but the attendant may
 * give a discount, set the delivery fee, and take the payment right away (split or with change).
 *
 * @phpstan-import-type ItemInput from CartPricer
 * @phpstan-import-type AddressInput from CustomerBook
 *
 * @phpstan-type PosInput array{
 *     fulfillment: string,
 *     customer: array{name: string|null, phone: string|null},
 *     address: AddressInput,
 *     delivery_zone_id: int|null,
 *     courier_id: int|null,
 *     delivery_fee_cents: int|null,
 *     discount_cents: int,
 *     notes: string|null,
 *     pay_later: bool,
 *     payment_method: string|null,
 *     change_for_cents: int|null,
 *     payments: list<array{method: string, amount_cents: int}>,
 *     complete_now: bool,
 *     items: list<ItemInput>
 * }
 */
final class PlacePosOrder
{
    public const WALK_IN_NAME = 'Cliente balcão';

    public function __construct(
        private readonly CartPricer $pricer,
        private readonly OrderRecorder $recorder,
        private readonly CustomerBook $customers,
        private readonly CashRegister $register,
    ) {}

    /**
     * @param  PosInput  $input
     */
    public function handle(Store $store, array $input, User $by): Order
    {
        $fulfillment = Fulfillment::tryFrom($input['fulfillment']);

        if (! in_array($fulfillment, [Fulfillment::Pickup, Fulfillment::Delivery], true)) {
            $this->fail('fulfillment', 'Escolha balcão ou entrega.');
        }

        $isDelivery = $fulfillment === Fulfillment::Delivery;
        $name = trim((string) $input['customer']['name']);
        $phone = $input['customer']['phone'];
        $address = $isDelivery ? $input['address'] : [];

        if ($isDelivery && ($name === '' || $phone === null)) {
            $this->fail('customer.name', 'Para entrega, informe o nome e o WhatsApp do cliente.');
        }

        if ($isDelivery && (($address['street'] ?? null) === null || ($address['number'] ?? null) === null)) {
            $this->fail('address.street', 'Informe a rua e o número para a entrega.');
        }

        if ($input['pay_later'] && ! $isDelivery) {
            $this->fail('payments', 'No balcão o pagamento é feito na hora.');
        }

        if ($input['complete_now'] && $isDelivery) {
            $this->fail('complete_now', 'Só vendas de balcão podem ser concluídas direto.');
        }

        if (! $input['pay_later'] && $this->register->current($store) === null) {
            $this->fail('cash', 'Abra o caixa antes de vender.');
        }

        $lines = $this->pricer->price($store, $input['items']);
        $subtotal = CartPricer::subtotal($lines);
        $zone = $isDelivery ? $this->deliveryZone($store, $input['delivery_zone_id']) : null;
        $courierId = $isDelivery ? $this->courier($store, $input['courier_id']) : null;
        $deliveryFee = $isDelivery ? ($input['delivery_fee_cents'] ?? $zone->fee_cents ?? 0) : 0;
        $discount = $input['discount_cents'];

        if ($discount > $subtotal + $deliveryFee) {
            $this->fail('discount', 'O desconto não pode ser maior que o total do pedido.');
        }

        $total = $subtotal + $deliveryFee - $discount;
        $settlement = $input['pay_later'] ? null : Settlement::of($total, array_map(
            fn (array $payment) => new Payment(PaymentMethod::from($payment['method']), $payment['amount_cents']),
            $input['payments'],
        ));
        $declared = $input['pay_later'] ? $this->declaredPayment($input, $total) : null;

        $order = DB::transaction(function () use ($store, $by, $input, $fulfillment, $lines, $subtotal, $deliveryFee, $discount, $total, $zone, $name, $phone, $address, $settlement, $declared, $courierId): Order {
            $this->pricer->reserveStock($lines);

            $customer = $phone === null ? null : $this->customers->remember($store, $name !== '' ? $name : self::WALK_IN_NAME, $phone, $address);

            $order = $this->recorder->record($store, [
                'customer_id' => $customer?->id,
                'customer_name' => $customer->name ?? ($name !== '' ? $name : self::WALK_IN_NAME),
                'customer_phone' => $phone,
                'channel' => OrderChannel::Pos,
                'fulfillment' => $fulfillment,
                'delivery_zip' => $address['zip'] ?? null,
                'delivery_street' => $address['street'] ?? null,
                'delivery_number' => $address['number'] ?? null,
                'delivery_district' => ($address['district'] ?? null) ?: $zone?->name,
                'delivery_complement' => $address['complement'] ?? null,
                'delivery_reference' => $address['reference'] ?? null,
                'delivery_zone_id' => $zone?->id,
                'courier_id' => $courierId,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discount,
                'delivery_fee_cents' => $deliveryFee,
                'total_cents' => $total,
                'payment_method' => $declared['method'] ?? PaymentMethod::Cash->value,
                'change_for_cents' => $declared['change_for_cents'] ?? null,
                'notes' => $input['notes'],
            ], $lines, $input['complete_now'] ? OrderStatus::Completed : OrderStatus::Accepted, $by);

            if ($settlement !== null) {
                $this->register->receive($order, $settlement, $by);
            }

            return $order;
        });

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  PosInput  $input
     * @return array{method: string, change_for_cents: int|null}
     */
    private function declaredPayment(array $input, int $total): array
    {
        $method = PaymentMethod::tryFrom((string) $input['payment_method']);

        if ($method === null) {
            $this->fail('payment_method', 'Escolha como o cliente vai pagar na entrega.');
        }

        $changeFor = $method === PaymentMethod::Cash ? $input['change_for_cents'] : null;

        if ($changeFor !== null && $changeFor < $total) {
            $this->fail('change_for', 'O troco deve ser para um valor maior ou igual ao total de R$ '.Money::toInput($total).'.');
        }

        return ['method' => $method->value, 'change_for_cents' => $changeFor];
    }

    /**
     * The courier chosen at the counter must be in the team.
     */
    private function courier(Store $store, ?int $userId): ?int
    {
        if ($userId === null) {
            return null;
        }

        if (! StoreMember::query()->where('store_id', $store->id)->where('user_id', $userId)->exists()) {
            $this->fail('courier_id', 'Escolha alguém da equipe para a entrega.');
        }

        return $userId;
    }

    /**
     * At the counter the zone is optional: the attendant may type the fee instead.
     */
    private function deliveryZone(Store $store, ?int $zoneId): ?DeliveryZone
    {
        if ($zoneId === null) {
            return null;
        }

        $zone = $store->deliveryZones()->whereKey($zoneId)->first();

        if (! $zone instanceof DeliveryZone) {
            $this->fail('delivery_zone_id', 'Região de entrega inválida.');
        }

        return $zone;
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
