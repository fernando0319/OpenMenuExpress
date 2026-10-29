<?php

namespace App\Messaging;

use App\Enums\Fulfillment;
use App\Enums\MessageEvent;
use App\Models\MessageTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Store;
use App\Support\Money;
use App\Support\SlotLabel;
use Carbon\CarbonImmutable;

/**
 * Builds the text of an automatic message from the store's template (or the default one).
 */
final class OrderMessageComposer
{
    /**
     * Placeholders available to merchants, with what they become.
     *
     * @return array<string, string>
     */
    public static function variables(): array
    {
        return [
            '{nome}' => 'Primeiro nome do cliente',
            '{pedido}' => 'Número do pedido',
            '{total}' => 'Total do pedido',
            '{valores}' => 'Subtotal, taxa de entrega, desconto e total, um por linha (só o que houver)',
            '{subtotal}' => 'Valor dos itens, sem a entrega',
            '{taxa_entrega}' => 'Taxa de entrega ("Grátis" se for zero; vazio na retirada)',
            '{desconto}' => 'Desconto do cupom (vazio se não houver)',
            '{itens}' => 'Lista dos itens',
            '{entrega}' => 'Entrega ou retirada',
            '{endereco}' => 'Endereço de entrega (vazio na retirada)',
            '{entrega_detalhes}' => '"Entrega em: endereço" ou "Retirada na loja", com a referência',
            '{agendamento}' => 'Horário agendado (vazio se for o quanto antes)',
            '{pagamento}' => 'Forma de pagamento',
            '{loja}' => 'Nome da loja',
            '{link_pedido}' => 'Link para acompanhar o pedido',
        ];
    }

    /**
     * The template the store uses for this event, or null when it is turned off.
     */
    public function templateFor(Store $store, MessageEvent $event): ?string
    {
        $custom = $store->messageTemplates()->where('event', $event->value)->first();

        if ($custom instanceof MessageTemplate) {
            return $custom->is_enabled ? $custom->body : null;
        }

        return $event->enabledByDefault() ? $event->defaultBody() : null;
    }

    public function render(string $template, Order $order): string
    {
        $store = $order->store;

        $isDelivery = $order->fulfillment === Fulfillment::Delivery;
        $address = $isDelivery ? $this->address($order) : '';

        $text = strtr($template, [
            '{nome}' => explode(' ', trim($order->customer_name))[0],
            '{pedido}' => (string) $order->number,
            '{total}' => self::money($order->total_cents),
            '{valores}' => $this->amounts($order),
            '{subtotal}' => self::money($order->subtotal_cents),
            '{taxa_entrega}' => $isDelivery ? ($order->delivery_fee_cents > 0 ? self::money($order->delivery_fee_cents) : 'Grátis') : '',
            '{desconto}' => $order->discount_cents > 0 ? '- '.self::money($order->discount_cents) : '',
            '{endereco}' => $address,
            '{entrega_detalhes}' => match ($order->fulfillment) {
                Fulfillment::Delivery => "Entrega em: {$address}".($order->delivery_reference ? "\nReferência: {$order->delivery_reference}" : ''),
                Fulfillment::Pickup => 'Retirada na loja'.($this->storeAddress($store) !== '' ? ': '.$this->storeAddress($store) : ''),
                default => '',
            },
            '{itens}' => $this->itemsList($order),
            '{entrega}' => $order->fulfillment->label(),
            '{agendamento}' => SlotLabel::describe($order->scheduled_for, $store->timezone, CarbonImmutable::now()) ?? '',
            '{pagamento}' => $order->paymentLabel(),
            '{loja}' => $store->name,
            '{link_pedido}' => $order->public_token === null
                ? ''
                : route('menu.orders.show', [$store->slug, $order->public_token]),
        ]);

        // Variables that came out empty must not leave a hole of blank lines behind.
        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace("/[ \t]+\n/", "\n", $text) ?? $text) ?? $text);
    }

    /**
     * "Rua das Flores, 120 (Casa 2) - Jardins, São Paulo".
     */
    private function address(Order $order): string
    {
        $street = implode(', ', array_filter([$order->delivery_street, $order->delivery_number]));
        $street .= $order->delivery_complement ? " ({$order->delivery_complement})" : '';
        $place = implode(', ', array_filter([$order->delivery_district, $order->delivery_city]));

        return implode(' - ', array_filter([$street, $place]));
    }

    private function storeAddress(Store $store): string
    {
        $street = implode(', ', array_filter([$store->address_street, $store->address_number]));

        return implode(' - ', array_filter([$street, $store->address_district]));
    }

    /**
     * The amounts one per line: the parts only when there is something to add or take off.
     */
    private function amounts(Order $order): string
    {
        $lines = [];

        if ($order->delivery_fee_cents > 0 || $order->discount_cents > 0) {
            $lines[] = 'Subtotal: '.self::money($order->subtotal_cents);
        }

        if ($order->fulfillment === Fulfillment::Delivery) {
            $lines[] = 'Taxa de entrega: '.($order->delivery_fee_cents > 0 ? self::money($order->delivery_fee_cents) : 'Grátis');
        }

        if ($order->discount_cents > 0) {
            $lines[] = 'Desconto'.($order->coupon_code ? " ({$order->coupon_code})" : '').': - '.self::money($order->discount_cents);
        }

        $lines[] = 'Total: '.self::money($order->total_cents);

        return implode("\n", $lines);
    }

    private static function money(int $cents): string
    {
        return 'R$ '.Money::toInput($cents);
    }

    private function itemsList(Order $order): string
    {
        return $order->items()->with('options')->get()
            ->map(function (OrderItem $item): string {
                $options = $item->options
                    ->map(fn (OrderItemOption $option) => $option->quantity > 1
                        ? "{$option->quantity}x {$option->option_name}"
                        : $option->option_name)
                    ->implode(', ');

                return "{$item->quantity}x {$item->product_name}".($options !== '' ? " ({$options})" : '');
            })
            ->implode("\n");
    }
}
