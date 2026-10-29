<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\MessageLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PrintAgent;
use App\Models\PrintJob;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use App\Services\Admin\PlatformMetrics;
use App\Services\Admin\StoreModeration;
use App\Services\Admin\SupportAccess;
use App\Services\Billing\Subscription;
use App\Services\Payments\StorePaymentMethods;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\StoreReport;
use App\Support\SupportSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Private panel of the platform owner: metrics of every hosted store and interventions.
 * Shows aggregates and store data, never the stores' customer lists.
 */
class AdminController extends Controller
{
    public function __construct(private readonly PlatformMetrics $metrics) {}

    public function dashboard(): Response
    {
        return Inertia::render('admin/Dashboard', [
            'totals' => $this->metrics->totals(),
            'daily' => $this->metrics->daily(),
            'newestStores' => Store::query()->latest('id')->limit(5)->get()->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'createdAt' => $store->created_at?->toIso8601String(),
            ]),
            'audit' => $this->auditRows(AdminAuditLog::query()->latest('id')->limit(10)->with(['user:id,name', 'store:id,name'])->get()),
        ]);
    }

    public function stores(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['recent', 'sales', 'orders', 'name'])],
            'status' => ['nullable', Rule::in(['all', 'active', 'suspended'])],
        ]);

        $search = trim((string) ($filters['search'] ?? ''));
        $sort = $filters['sort'] ?? 'recent';
        $status = $filters['status'] ?? 'all';

        $stores = $this->metrics->storesWithActivity()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('slug', 'like', "%{$search}%")))
            ->when($status === 'active', fn ($query) => $query->whereNull('suspended_at'))
            ->when($status === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($sort === 'recent', fn ($query) => $query->latest('id'))
            ->when($sort === 'orders', fn ($query) => $query->orderByDesc('orders_30d'))
            ->when($sort === 'sales', fn ($query) => $query->orderByRaw('coalesce(order_sales_30d, 0) + coalesce(tab_sales_30d, 0) desc'))
            ->when($sort === 'name', fn ($query) => $query->orderBy('name'))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'owner' => $store->users->first()?->name,
                'membersCount' => (int) $store->getAttribute('members_count'),
                'createdAt' => $store->created_at?->toIso8601String(),
                'lastOrderAt' => $this->isoOrNull($store->getAttribute('last_order_at')),
                'orders30d' => (int) $store->getAttribute('orders_30d'),
                'sales30dCents' => (int) $store->getAttribute('order_sales_30d') + (int) $store->getAttribute('tab_sales_30d'),
                'whatsapp' => $store->whatsapp_enabled && $store->whatsapp_instance !== null,
                'isSuspended' => $store->isSuspended(),
                'subscription' => Subscription::for($store)->label(),
            ]);

        return Inertia::render('admin/Stores', [
            'stores' => $stores,
            'filters' => ['search' => $search, 'sort' => $sort, 'status' => $status],
        ]);
    }

    public function store(Store $store, StorePaymentMethods $paymentMethods): Response
    {
        $period = ReportPeriod::from('30d', null, null, $store->timezone);
        $report = new StoreReport($store, $period);
        $since = now()->subDays(7);

        return Inertia::render('admin/StoreDetail', [
            'store' => [
                'id' => $store->id,
                'name' => $store->name,
                'slug' => $store->slug,
                'whatsappNumber' => $store->whatsapp,
                'createdAt' => $store->created_at?->toIso8601String(),
                'timezone' => $store->timezone,
                'isPaused' => $store->is_paused,
                'isSuspended' => $store->isSuspended(),
                'suspendedAt' => $store->suspended_at?->toIso8601String(),
                'suspensionReason' => $store->suspension_reason,
            ],
            'members' => StoreMember::query()
                ->where('store_id', $store->id)
                ->with('user')
                ->get()
                ->filter(fn (StoreMember $membership) => $membership->user instanceof User)
                ->map(fn (StoreMember $membership) => [
                    'id' => $membership->user_id,
                    'name' => $membership->user->name ?? '',
                    'login' => $membership->user?->login() ?? '',
                    'role' => $membership->role->label(),
                ])
                ->values(),
            'subscription' => [
                ...Subscription::for($store)->toArray(),
                'planId' => $store->plan_id,
                'isComplimentary' => $store->is_complimentary,
                'trialEndsAt' => $store->trial_ends_at?->toIso8601String(),
                'paidUntil' => $store->paid_until?->toIso8601String(),
            ],
            'plans' => Plan::query()->orderBy('sort_order')->get(['id', 'name', 'is_active']),
            'billingEnabled' => (bool) config('billing.enabled'),
            'paymentMethods' => array_map(
                fn (array $row) => ['label' => $row['method']->label(), 'active' => $row['active']],
                $paymentMethods->settings($store),
            ),
            'totals' => $report->totals(),
            'daily' => $report->daily(),
            'health' => [
                'whatsappEnabled' => $store->whatsapp_enabled,
                'whatsappConnected' => $store->whatsapp_instance !== null,
                'messageFailures7d' => MessageLog::query()->where('store_id', $store->id)->where('status', MessageLog::STATUS_FAILED)->where('created_at', '>=', $since)->count(),
                'printFailures7d' => PrintJob::query()->where('store_id', $store->id)->where('status', PrintJob::STATUS_FAILED)->where('updated_at', '>=', $since)->count(),
                'agents' => $store->printAgents()->get()->map(fn (PrintAgent $agent) => [
                    'name' => $agent->name,
                    'online' => $agent->isOnline(),
                    'lastSeenAt' => $agent->last_seen_at?->toIso8601String(),
                ]),
            ],
            // Only what is needed to spot problems: no customer names or phones.
            'recentOrders' => $store->orders()->latest('id')->limit(10)->get()->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'channel' => $order->channel->label(),
                'status' => $order->status->label(),
                'totalCents' => $order->total_cents,
                'placedAt' => $order->placed_at->toIso8601String(),
            ]),
            'audit' => $this->auditRows(AdminAuditLog::query()->where('store_id', $store->id)->latest('id')->limit(20)->with(['user:id,name', 'store:id,name'])->get()),
        ]);
    }

    public function suspend(Request $request, Store $store, StoreModeration $moderation): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], [
            'reason.required' => 'Explique o motivo; ele aparece para o lojista.',
            'reason.min' => 'Explique o motivo; ele aparece para o lojista.',
        ]);

        $moderation->suspend($store, $this->admin($request), $data['reason'], $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$store->name} foi suspensa."]);

        return back();
    }

    /**
     * Opens the store's panel in support mode (read-only unless asked otherwise).
     */
    public function startSupport(Request $request, Store $store, SupportAccess $access): RedirectResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in([SupportSession::MODE_READ, SupportSession::MODE_FULL])],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ], [
            'reason.required' => 'Explique o motivo do acesso; o lojista vê esse registro.',
            'reason.min' => 'Explique o motivo do acesso; o lojista vê esse registro.',
        ]);

        $access->start($request, $this->admin($request), $store, $data['mode'], $data['reason']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Modo suporte em {$store->name} por ".SupportSession::MINUTES.' minutos.']);

        return to_route('dashboard');
    }

    public function stopSupport(Request $request, SupportAccess $access): RedirectResponse
    {
        $storeId = $access->end($request, $this->admin($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Você saiu do modo suporte.']);

        return $storeId !== null ? to_route('admin.stores.show', $storeId) : to_route('admin.dashboard');
    }

    public function reactivate(Request $request, Store $store, StoreModeration $moderation): RedirectResponse
    {
        $moderation->reactivate($store, $this->admin($request), $request->ip());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$store->name} foi reativada."]);

        return back();
    }

    private function admin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_super_admin === true, 403);

        return $user;
    }

    private function isoOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->toIso8601String() : null;
    }

    /**
     * @param  iterable<AdminAuditLog>  $logs
     * @return list<array{id: int, action: string, store: string|null, storeId: int|null, by: string|null, details: array<string, mixed>|null, at: string|null}>
     */
    private function auditRows(iterable $logs): array
    {
        $rows = [];

        foreach ($logs as $log) {
            $rows[] = [
                'id' => $log->id,
                'action' => $log->actionLabel(),
                'store' => $log->store?->name,
                'storeId' => $log->store_id,
                'by' => $log->user?->name,
                'details' => $log->details,
                'at' => $log->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
