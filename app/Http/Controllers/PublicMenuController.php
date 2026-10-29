<?php

namespace App\Http\Controllers;

use App\Enums\CouponType;
use App\Enums\DeliveryFeeMode;
use App\Enums\Fulfillment;
use App\Enums\OnlinePaymentMethod;
use App\Http\Requests\PlaceOrderRequest;
use App\Models\Banner;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Store;
use App\Payments\OnlinePayments;
use App\Services\Catalog\MenuCatalog;
use App\Services\Coupons\CouponService;
use App\Services\Delivery\DeliveryTracking;
use App\Services\Orders\PlaceOnlineOrder;
use App\Services\Payments\StorePaymentMethods;
use App\Services\Scheduling\StoreSchedule;
use App\Support\Phone;
use App\Support\SlotLabel;
use App\Support\StoreBranding;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer-facing menu: no account, the customer is remembered by an encrypted cookie.
 */
class PublicMenuController extends Controller
{
    private const CUSTOMER_COOKIE_DAYS = 90;

    public function show(Request $request, MenuCatalog $catalog, StorePaymentMethods $paymentMethods, OnlinePayments $onlinePayments, string $storeSlug): Response
    {
        $store = $this->findStore($storeSlug);
        $schedule = StoreSchedule::for($store);
        $now = CarbonImmutable::now();
        $isOpen = $schedule->isOpenAt($now);

        return Inertia::render('menu/Show', [
            'store' => [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->description,
                'whatsapp' => $store->whatsapp,
                'isOpen' => $isOpen,
                'isPaused' => $store->is_paused,
                'nextOpening' => $isOpen ? null : SlotLabel::describe($schedule->nextOpening($now), $store->timezone, $now),
                'acceptsDelivery' => $store->accepts_delivery && $store->delivery_fee_mode === DeliveryFeeMode::Zone,
                'acceptsPickup' => $store->accepts_pickup,
                'minimumOrderCents' => $store->minimum_order_cents,
                'address' => $this->storeAddress($store),
            ],
            'branding' => StoreBranding::for($store),
            'hours' => $schedule->weekly(),
            'slots' => array_map(
                fn (CarbonImmutable $slot) => [
                    'value' => $slot->toIso8601ZuluString(),
                    'day' => SlotLabel::day($slot, $store->timezone, $now),
                    'time' => $slot->setTimezone($store->timezone)->format('H:i'),
                ],
                $schedule->availableSlots($now),
            ),
            'banners' => $this->banners($store),
            'categories' => $catalog->for($store),
            'deliveryZones' => $store->deliveryZones()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'fee_cents']),
            'paymentMethods' => $paymentMethods->options($store),
            // Paid now in the app, straight to the store's account.
            'onlinePaymentMethods' => array_map(
                fn (string $method) => ['value' => $method, 'label' => OnlinePaymentMethod::from($method)->label()],
                array_keys($onlinePayments->offered($store)),
            ),
            'customer' => $this->rememberedCustomer($request, $store),
        ]);
    }

    public function placeOrder(PlaceOrderRequest $request, PlaceOnlineOrder $placeOrder, string $storeSlug): RedirectResponse
    {
        $store = $this->findStore($storeSlug);

        $order = $placeOrder->handle($store, $request->orderInput());

        Cookie::queue($this->customerCookieName($store), (string) json_encode([
            'name' => $order->customer_name,
            'phone' => $order->customer_phone,
            'address' => [
                'zip' => $order->delivery_zip,
                'street' => $order->delivery_street,
                'number' => $order->delivery_number,
                'district' => $order->delivery_district,
                'complement' => $order->delivery_complement,
                'reference' => $order->delivery_reference,
            ],
            'delivery_zone_id' => $order->delivery_zone_id,
        ]), 60 * 24 * self::CUSTOMER_COOKIE_DAYS);

        return to_route('menu.orders.show', [$store->slug, $order->public_token]);
    }

    /**
     * Live preview for the cart. The order itself re-checks the coupon under lock.
     */
    public function checkCoupon(Request $request, CouponService $coupons, string $storeSlug): JsonResponse
    {
        $store = $this->findStore($storeSlug);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal_cents' => ['required', 'integer', 'min:0'],
            'fulfillment' => ['nullable', 'in:delivery,pickup'],
            'delivery_zone_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:25'],
        ]);

        $phone = Phone::normalize($data['phone'] ?? null);
        $customer = $phone === null ? null : $store->customers()->where('phone', $phone)->first();
        $fulfillment = Fulfillment::tryFrom((string) ($data['fulfillment'] ?? ''));
        $zone = $fulfillment === Fulfillment::Delivery && isset($data['delivery_zone_id'])
            ? $store->deliveryZones()->where('is_active', true)->whereKey((int) $data['delivery_zone_id'])->first()
            : null;

        try {
            $applied = $coupons->evaluate($store, $data['code'], (int) $data['subtotal_cents'], $zone === null ? 0 : $zone->fee_cents, $fulfillment, $customer);
        } catch (ValidationException $exception) {
            return response()->json(['valid' => false, 'message' => $exception->validator->errors()->first('coupon')], 422);
        }

        return response()->json([
            'valid' => true,
            'code' => $applied->coupon->code,
            'label' => $applied->label(),
            'discountCents' => $applied->discountCents,
            'isFreeDelivery' => $applied->coupon->type === CouponType::FreeDelivery,
        ]);
    }

    public function showOrder(DeliveryTracking $tracking, string $storeSlug, string $token): Response
    {
        $store = $this->findStore($storeSlug);

        $order = $store->orders()
            ->where('public_token', $token)
            ->with('items.options')
            ->firstOrFail();

        return Inertia::render('menu/OrderPlaced', [
            'store' => ['name' => $store->name, 'slug' => $store->slug, 'whatsapp' => $store->whatsapp],
            'branding' => StoreBranding::for($store),
            // Live position of the courier, only while this order is on its way.
            'tracking' => $tracking->forCustomer($order),
            'mapTiles' => ['url' => config('maps.tile_url'), 'attribution' => config('maps.attribution')],
            'order' => [
                'token' => $order->public_token,
                'number' => $order->number,
                'status' => $order->status->label(),
                'fulfillment' => $order->fulfillment->label(),
                'placedAt' => $order->placed_at->toIso8601String(),
                'scheduledFor' => SlotLabel::describe($order->scheduled_for, $store->timezone, CarbonImmutable::now()),
                'subtotalCents' => $order->subtotal_cents,
                'deliveryFeeCents' => $order->delivery_fee_cents,
                'discountCents' => $order->discount_cents,
                'couponCode' => $order->coupon_code,
                'totalCents' => $order->total_cents,
                'paymentMethod' => $order->paymentLabel(),
                'changeForCents' => $order->change_for_cents,
                'address' => $order->delivery_street === null ? null : trim(
                    "{$order->delivery_street}, {$order->delivery_number} {$order->delivery_complement} — {$order->delivery_district}",
                ),
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'totalCents' => $item->total_cents,
                    'notes' => $item->notes,
                    'options' => $item->options->map(fn (OrderItemOption $option) => [
                        'group' => $option->group_name,
                        'name' => $option->option_name,
                        'quantity' => $option->quantity,
                    ]),
                ]),
            ],
            'payment' => $this->paymentData($order),
        ]);
    }

    /**
     * Polled by the order page while an online payment is open. Asks the provider at most every
     * few seconds per order (notifications usually arrive first).
     */
    public function paymentStatus(OnlinePayments $payments, string $storeSlug, string $token): JsonResponse
    {
        $order = $this->findStore($storeSlug)->orders()->where('public_token', $token)->firstOrFail();
        $payment = $order->onlinePayments()->with('account')->latest('id')->first();

        if ($payment instanceof OnlinePayment && $payment->status === OnlinePayment::PENDING
            && ($payment->last_checked_at === null || $payment->last_checked_at->lessThan(now()->subSeconds(8)))) {
            $payment = $payments->sync($payment);

            if ($payment->status === OnlinePayment::PENDING && $payment->expires_at?->lessThan(now()->subMinute())) {
                $payments->apply($payment, OnlinePayment::EXPIRED);
            }
        }

        return response()->json([
            'payment' => $this->paymentData($order->refresh()),
            'orderStatus' => $order->status->label(),
        ]);
    }

    /**
     * What the customer needs to pay (or the result), for orders paid online.
     *
     * @return array<string, mixed>|null
     */
    private function paymentData(Order $order): ?array
    {
        $payment = $order->onlinePayments()->latest('id')->first();

        if (! $payment instanceof OnlinePayment) {
            return null;
        }

        $open = $payment->status === OnlinePayment::PENDING;

        return [
            'method' => $payment->method->value,
            'methodLabel' => $payment->method->orderLabel(),
            'provider' => $payment->provider->label(),
            'status' => $payment->status,
            'statusLabel' => $payment->statusLabel(),
            'amountCents' => $payment->amount_cents,
            'pixCode' => $open ? $payment->pix_code : null,
            'pixQrSvg' => $open && $payment->pix_code !== null ? $this->qrSvg($payment->pix_code) : null,
            'checkoutUrl' => $open ? $payment->checkout_url : null,
            'expiresAt' => $payment->expires_at?->toIso8601String(),
        ];
    }

    private function qrSvg(string $text): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(260, 1), new SvgImageBackEnd));

        return $writer->writeString($text);
    }

    /**
     * A suspended store takes no orders and shows no data.
     */
    private function storeAddress(Store $store): ?string
    {
        $street = implode(', ', array_filter([$store->getAttribute('address_street'), $store->getAttribute('address_number')]));
        $place = implode(' - ', array_filter([$store->getAttribute('address_district'), $store->getAttribute('address_city'), $store->getAttribute('address_state')]));
        $address = implode(', ', array_filter([$street, $place]));

        return $address === '' ? null : $address;
    }

    private function findStore(string $slug): Store
    {
        $store = Store::query()->where('slug', $slug)->firstOrFail();

        abort_if($store->isSuspended(), 503, 'Cardápio indisponível no momento.');

        return $store;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rememberedCustomer(Request $request, Store $store): ?array
    {
        $cookie = $request->cookie($this->customerCookieName($store));
        $data = is_string($cookie) ? json_decode($cookie, true) : null;

        return is_array($data) ? $data : null;
    }

    private function customerCookieName(Store $store): string
    {
        return 'customer_'.$store->id;
    }

    /**
     * @return list<array{id: int, title: string, imageUrl: string, linkType: string, linkTarget: string|null}>
     */
    private function banners(Store $store): array
    {
        return array_values($store->banners()
            ->showing()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Banner $banner) => [
                'id' => $banner->id,
                'title' => $banner->title,
                'imageUrl' => $banner->imageUrl(),
                'linkType' => $banner->link_type->value,
                'linkTarget' => $banner->link_target,
            ])
            ->all());
    }
}
