<?php

namespace App\Http\Controllers\Tables;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\ReceivePaymentRequest;
use App\Http\Requests\Tables\TabRoundRequest;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemOption;
use App\Models\Tab;
use App\Models\User;
use App\Services\Cash\CashRegister;
use App\Services\Catalog\MenuCatalog;
use App\Services\Payments\StorePaymentMethods;
use App\Services\Tables\TabBill;
use App\Services\Tables\TabService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tables screen, used by waiters on the phone and by the counter to close bills.
 */
class TableController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly TabService $tabs) {}

    public function index(): Response
    {
        $store = $this->currentStore();

        $tables = $store->diningTables()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['activeTab.joinedTables:id,name,joined_tab_id', 'joinedTab.table:id,name'])
            ->get();

        $consumed = $this->consumedByTab($tables->map(fn (DiningTable $table) => $table->activeTab?->id)->filter()->all());

        return Inertia::render('tables/Index', [
            'tables' => $tables->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
                'seats' => $table->seats,
                'status' => $table->joinedTab !== null ? 'joined' : ($table->activeTab?->status->value ?? 'free'),
                // Joined to another table's tab: the card points there.
                'joinedTo' => $table->joinedTab !== null ? ['id' => $table->joinedTab->table->id, 'name' => $table->joinedTab->table->name] : null,
                'joinedNames' => $table->activeTab?->joinedTables->pluck('name')->all() ?? [],
                'people' => $table->activeTab?->people,
                'customerName' => $table->activeTab?->customer_name,
                'openedAt' => $table->activeTab?->opened_at->toIso8601String(),
                'consumedCents' => $table->activeTab === null ? 0 : ($consumed[$table->activeTab->id] ?? 0),
            ]),
            'hasTables' => $store->diningTables()->exists(),
        ]);
    }

    public function show(DiningTable $table, MenuCatalog $catalog, CashRegister $register, StorePaymentMethods $paymentMethods): Response|RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($table);
        $store = $this->currentStore();

        // A joined table is served from the table that holds the tab.
        if ($table->joinedTab !== null && $table->joinedTab->status->isActive()) {
            return to_route('tables.show', $table->joinedTab->dining_table_id);
        }

        $tab = $table->activeTab()->with('openedBy:id,name')->first();

        return Inertia::render('tables/Show', [
            'table' => ['id' => $table->id, 'name' => $table->name, 'seats' => $table->seats],
            'tab' => $tab === null ? null : $this->tabPayload($tab),
            'categories' => $catalog->for($store),
            'paymentMethods' => $paymentMethods->options($store),
            'freeTables' => $tab === null ? [] : $store->diningTables()
                ->where('is_active', true)
                ->free()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name']),
            // Tables that can join this tab: free ones, and occupied ones whose tab would move over.
            'joinableTables' => $tab === null ? [] : $store->diningTables()
                ->where('is_active', true)
                ->whereNull('joined_tab_id')
                ->whereKeyNot($table->id)
                ->with('activeTab')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->map(fn (DiningTable $other) => ['id' => $other->id, 'name' => $other->name, 'occupied' => $other->activeTab !== null]),
            'canClose' => Gate::allows('operate-pos'),
            'cashOpen' => $register->current($store) !== null,
        ]);
    }

    public function open(Request $request, DiningTable $table): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($table);

        $data = $request->validate([
            'people' => ['nullable', 'integer', 'min:1', 'max:100'],
            'customer_name' => ['nullable', 'string', 'max:80'],
        ]);

        $this->tabs->open($table, $this->user($request), $data['people'] ?? null, $data['customer_name'] ?? null);

        return back();
    }

    public function addRound(TabRoundRequest $request, Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);

        $order = $this->tabs->addRound($tab, $request->roundItems(), $this->user($request), $request->notes());

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pedido nº {$order->number} enviado para a cozinha."]);

        return back();
    }

    public function requestBill(Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);

        $printed = $this->tabs->requestBill($tab);

        Inertia::flash('toast', ['type' => 'success', 'message' => $printed
            ? 'Conta pedida. A conferência foi enviada para impressão.'
            : 'Conta pedida.']);

        return back();
    }

    public function transfer(Request $request, Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);

        $data = $request->validate(['table_id' => ['required', 'integer']]);
        $target = $this->currentStore()->diningTables()->whereKey($data['table_id'])->firstOrFail();

        $this->tabs->transfer($tab, $target);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Conta transferida para a {$target->name}."]);

        return to_route('tables.show', $target);
    }

    public function join(Request $request, Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);

        $data = $request->validate(['table_id' => ['required', 'integer']]);
        $other = $this->currentStore()->diningTables()->whereKey($data['table_id'])->firstOrFail();

        $this->tabs->join($tab, $other, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$other->name} junta a esta mesa. Tudo vai para uma conta só."]);

        return back();
    }

    public function separate(Request $request, Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);

        $data = $request->validate(['table_id' => ['required', 'integer']]);
        $table = $this->currentStore()->diningTables()->whereKey($data['table_id'])->firstOrFail();

        $this->tabs->separate($tab, $table);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$table->name} separada e livre. O consumo continua nesta conta."]);

        return back();
    }

    public function close(ReceivePaymentRequest $request, Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);
        Gate::authorize('operate-pos');

        $tab = $this->tabs->close($tab, $request->payments(), $request->boolean('service_fee', true), $request->discountCents(), $this->user($request));

        $change = array_sum(array_map(fn ($payment) => $payment->amountCents, $request->payments())) - $tab->total_cents;

        Inertia::flash('toast', ['type' => 'success', 'message' => $change > 0
            ? 'Conta fechada. Troco: R$ '.Money::toInput($change).'.'
            : 'Conta fechada. Mesa liberada.']);

        return to_route('tables.index');
    }

    public function release(Request $request, Tab $tab): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($tab);

        $this->tabs->release($tab, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mesa liberada.']);

        return to_route('tables.index');
    }

    /**
     * @param  array<int, int>  $tabIds
     * @return array<int, int>
     */
    private function consumedByTab(array $tabIds): array
    {
        if ($tabIds === []) {
            return [];
        }

        return Order::query()
            ->whereIn('tab_id', $tabIds)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->groupBy('tab_id')
            ->selectRaw('tab_id, sum(total_cents) as consumed')
            ->pluck('consumed', 'tab_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function tabPayload(Tab $tab): array
    {
        $rounds = $tab->orders()->with('items.options')->orderBy('id')->get();

        return [
            'id' => $tab->id,
            'status' => $tab->status->value,
            'statusLabel' => $tab->status->label(),
            'people' => $tab->people,
            'customerName' => $tab->customer_name,
            'openedAt' => $tab->opened_at->toIso8601String(),
            'openedBy' => $tab->openedBy?->name,
            'joinedTables' => $tab->joinedTables()->orderBy('sort_order')->orderBy('id')->get(['id', 'name'])
                ->map(fn (DiningTable $table) => ['id' => $table->id, 'name' => $table->name]),
            'bill' => TabBill::for($tab)->toArray(),
            'rounds' => $rounds->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'status' => $order->status->value,
                'statusLabel' => $order->status->label(),
                'editable' => ! $order->status->isFinal(),
                'placedAt' => $order->placed_at->toIso8601String(),
                'totalCents' => $order->total_cents,
                'notes' => $order->notes,
                'items' => $order->items->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                    'totalCents' => $item->total_cents,
                    'notes' => $item->notes,
                    'options' => $item->options
                        ->map(fn (OrderItemOption $option) => $option->quantity > 1 ? "{$option->quantity}× {$option->option_name}" : $option->option_name)
                        ->implode(', '),
                ]),
            ]),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
