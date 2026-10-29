<?php

namespace App\Http\Middleware;

use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Services\Billing\Subscription;
use App\Support\CurrentStore;
use App\Support\DesktopApp;
use App\Support\StoreBranding;
use App\Support\SupportSession;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'currentStore' => fn () => $this->currentStoreProps(),
            'support' => fn () => $this->supportProps($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Download link of the Windows app, for people signed in to the panel.
            'desktopApp' => fn () => $request->user() !== null ? DesktopApp::latest() : null,
        ];
    }

    /**
     * @return array{id: int, name: string, slug: string, role: string|null, canManageCatalog: bool, canManageStore: bool, canManageFinance: bool, canOperatePos: bool, canServeTables: bool, canViewOrders: bool, hasDeliveries: bool, palette: array{light: array{primary: string, foreground: string}, dark: array{primary: string, foreground: string}, custom: bool}, subscription: array<string, mixed>, otherStores: list<array{id: int, name: string}>}|null
     */
    private function currentStoreProps(): ?array
    {
        $currentStore = app(CurrentStore::class);
        $store = $currentStore->get();

        if ($store === null) {
            return null;
        }

        return [
            'id' => $store->id,
            'name' => $store->name,
            'slug' => $store->slug,
            'role' => $currentStore->role()?->value,
            'canManageCatalog' => request()->user()?->can('manage-catalog') ?? false,
            'canManageStore' => request()->user()?->can('manage-store') ?? false,
            'canManageFinance' => request()->user()?->can('manage-finance') ?? false,
            'canOperatePos' => request()->user()?->can('operate-pos') ?? false,
            'canServeTables' => request()->user()?->can('serve-tables') ?? false,
            'canViewOrders' => request()->user()?->can('view-orders') ?? false,
            // Couriers always; anyone else while they have a delivery assigned.
            'hasDeliveries' => $currentStore->role() === StoreRole::Courier || $store->orders()
                ->where('courier_id', request()->user()?->id)
                ->whereIn('status', [OrderStatus::Accepted, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::OutForDelivery])
                ->exists(),
            'palette' => StoreBranding::panelPalette($store),
            'subscription' => Subscription::for($store)->toArray(),
            'otherStores' => SupportSession::current(request()) !== null ? [] : array_values(request()->user()?->stores()
                ->where('stores.id', '!=', $store->id)
                ->orderBy('name')
                ->get(['stores.id', 'stores.name'])
                ->map(fn ($other) => ['id' => (int) $other->id, 'name' => (string) $other->name])
                ->all() ?? []),
        ];
    }

    /**
     * The banner shown while the platform admin is in a store's panel.
     *
     * @return array{storeName: string, mode: string, expiresAt: string, reason: string}|null
     */
    private function supportProps(Request $request): ?array
    {
        $session = $request->user()?->is_super_admin === true ? SupportSession::current($request) : null;

        return $session === null ? null : [
            'storeName' => $session['store_name'],
            'mode' => $session['mode'],
            'expiresAt' => $session['expires_at'],
            'reason' => $session['reason'],
        ];
    }
}
