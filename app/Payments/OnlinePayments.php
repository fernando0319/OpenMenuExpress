<?php

namespace App\Payments;

use App\Enums\Module;
use App\Enums\OnlinePaymentMethod;
use App\Enums\OrderStatus;
use App\Enums\PaymentProvider;
use App\Events\OrderPlaced;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\PaymentAccount;
use App\Models\Store;
use App\Payments\Gateways\BtcPayGateway;
use App\Payments\Gateways\MercadoPagoGateway;
use App\Payments\Gateways\SicoobPixGateway;
use App\Services\Billing\Subscription;
use App\Services\Orders\ChangeOrderStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Online payments of orders: which methods a store offers, the charge of each order, and what
 * happens when the provider says it was paid, expired or refunded.
 *
 * An order paid online is born "aguardando pagamento": the store does not see it, nothing prints
 * and no message goes out until the payment is confirmed. Then it enters like any other order.
 */
final class OnlinePayments
{
    public function __construct(private readonly ChangeOrderStatus $changeStatus) {}

    public static function gateway(PaymentProvider $provider): PaymentGateway
    {
        return match ($provider) {
            PaymentProvider::MercadoPago => app(MercadoPagoGateway::class),
            PaymentProvider::Sicoob => app(SicoobPixGateway::class),
            PaymentProvider::BtcPay => app(BtcPayGateway::class),
        };
    }

    /**
     * The online methods the customer can choose now, each with the account that takes it.
     *
     * @return array<string, PaymentAccount> method value => account
     */
    public function offered(Store $store): array
    {
        if (! Subscription::for($store)->hasModule(Module::OnlinePayments)) {
            return [];
        }

        $offered = [];

        foreach ($store->paymentAccounts()->where('is_active', true)->get() as $account) {
            foreach ($account->provider->methods() as $method) {
                if ($account->takes($method) && ! isset($offered[$method->value])) {
                    $offered[$method->value] = $account;
                }
            }
        }

        // The menu shows them in this order.
        return array_filter(
            array_merge(array_fill_keys(array_column(OnlinePaymentMethod::cases(), 'value'), null), $offered),
        );
    }

    public function accountFor(Store $store, OnlinePaymentMethod $method): ?PaymentAccount
    {
        return $this->offered($store)[$method->value] ?? null;
    }

    /**
     * Creates the charge of an order just placed. If the provider fails, the order is cancelled
     * (stock and coupon back) and the customer is asked to choose another way to pay.
     */
    public function start(Order $order, OnlinePaymentMethod $method, PaymentAccount $account): OnlinePayment
    {
        $store = $order->store;
        $payment = $order->onlinePayments()->create([
            'store_id' => $order->store_id,
            'payment_account_id' => $account->id,
            'provider' => $account->provider,
            'method' => $method,
            'status' => OnlinePayment::PENDING,
            'amount_cents' => $order->total_cents,
        ]);

        $context = new ChargeContext(
            amountCents: $order->total_cents,
            description: "Pedido nº {$order->number} - {$store->name}",
            notificationUrl: route('payments.webhook', $account->webhook_token),
            returnUrl: route('menu.orders.show', [$store->slug, $order->public_token]),
            expiresAt: CarbonImmutable::now()->addMinutes((int) config('payments.expiration_minutes')),
            orderToken: (string) $order->public_token,
        );

        try {
            $charge = self::gateway($account->provider)->createCharge($account, $payment, $method, $context);
        } catch (PaymentException $exception) {
            $payment->update(['status' => OnlinePayment::FAILED, 'note' => mb_substr($exception->getMessage(), 0, 255)]);
            $account->update(['last_error' => mb_substr($exception->getMessage(), 0, 255)]);
            $this->changeStatus->handle($order, OrderStatus::Cancelled);

            throw ValidationException::withMessages([
                'payment_method' => 'Não foi possível gerar o pagamento online agora. Escolha outra forma de pagamento.',
            ]);
        }

        $payment->update([
            'provider_ref' => $charge->providerRef,
            'pix_code' => $charge->pixCode,
            'checkout_url' => $charge->checkoutUrl,
            'expires_at' => $charge->expiresAt ?? $context->expiresAt,
            'last_checked_at' => now(),
        ]);

        return $payment;
    }

    /**
     * Asks the provider and applies what it says.
     */
    public function sync(OnlinePayment $payment): OnlinePayment
    {
        $account = $payment->account;

        if ($account === null || $payment->provider_ref === null) {
            return $payment;
        }

        try {
            $status = self::gateway($payment->provider)->status($account, $payment);
        } catch (PaymentException $exception) {
            Log::warning('Online payment status failed', ['payment' => $payment->id, 'error' => $exception->getMessage()]);
            $payment->update(['last_checked_at' => now()]);

            return $payment;
        }

        $this->apply($payment, $status);

        return $payment->refresh();
    }

    public function apply(OnlinePayment $payment, string $status): void
    {
        $placed = null;

        DB::transaction(function () use ($payment, $status, &$placed): void {
            $current = OnlinePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $current->update(['last_checked_at' => now()]);

            if ($status === $current->status || $status === OnlinePayment::PENDING) {
                return;
            }

            $order = Order::query()->whereKey($current->order_id)->lockForUpdate()->firstOrFail();

            if ($status === OnlinePayment::PAID && $current->status === OnlinePayment::PENDING) {
                $current->update(['status' => OnlinePayment::PAID, 'paid_at' => now()]);
                $order->update(['paid_at' => now()]);

                if ($order->status === OrderStatus::PendingPayment) {
                    $order->update(['status' => OrderStatus::Pending]);
                    $order->statusHistory()->create(['from_status' => OrderStatus::PendingPayment, 'to_status' => OrderStatus::Pending, 'created_at' => now()]);
                    $placed = $order;
                }

                return;
            }

            if ($status === OnlinePayment::REFUNDED) {
                $current->update(['status' => OnlinePayment::REFUNDED, 'refunded_at' => $current->refunded_at ?? now()]);

                return;
            }

            if (in_array($status, [OnlinePayment::EXPIRED, OnlinePayment::FAILED], true) && $current->status === OnlinePayment::PENDING) {
                $current->update(['status' => $status]);
            }
        });

        $payment->refresh();

        if ($placed instanceof Order) {
            // Now the store sees it: alert, ticket and messages, as for any new order.
            OrderPlaced::dispatch($placed->refresh());

            return;
        }

        $order = $payment->order()->first();

        if ($order !== null && $order->status === OrderStatus::PendingPayment && in_array($payment->status, [OnlinePayment::EXPIRED, OnlinePayment::FAILED], true)) {
            $this->changeStatus->handle($order, OrderStatus::Cancelled);
        }

        // Paid after the order was already cancelled (late Pix): give the money back.
        if ($order !== null && $order->status === OrderStatus::Cancelled && $payment->status === OnlinePayment::PAID) {
            $this->refund($payment);
        }
    }

    /**
     * Checks charges still open, and expires those past their time.
     */
    public function syncPending(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        OnlinePayment::query()
            ->with(['account', 'order'])
            ->where('status', OnlinePayment::PENDING)
            ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $now->subMinute()))
            ->orderBy('id')
            ->chunkById(100, function ($payments) use ($now, &$count): void {
                foreach ($payments as $payment) {
                    $payment = $this->sync($payment);

                    if ($payment->status === OnlinePayment::PENDING && $payment->expires_at !== null && $payment->expires_at->lessThan($now->subMinutes(2))) {
                        $this->apply($payment, OnlinePayment::EXPIRED);
                    }

                    $count++;
                }
            });

        return $count;
    }

    /**
     * When the store cancels an order paid online, the money goes back through the provider.
     *
     * @return bool|null true refunded, false must be refunded by hand, null nothing to refund
     */
    public function refundOrder(Order $order): ?bool
    {
        $payment = $order->onlinePayments()->where('status', OnlinePayment::PAID)->latest('id')->first();

        return $payment instanceof OnlinePayment ? $this->refund($payment) : null;
    }

    private function refund(OnlinePayment $payment): bool
    {
        $account = $payment->account;

        try {
            $done = $account !== null && self::gateway($payment->provider)->refund($account, $payment);
        } catch (PaymentException $exception) {
            $payment->update(['note' => mb_substr('Estorno falhou: '.$exception->getMessage(), 0, 255)]);

            return false;
        }

        $payment->update($done
            ? ['status' => OnlinePayment::REFUNDED, 'refunded_at' => now(), 'note' => null]
            : ['note' => 'Estorne pelo painel do '.$payment->provider->label().'.']);

        return $done;
    }
}
