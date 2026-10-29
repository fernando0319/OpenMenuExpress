<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Registers the store's tables and the service fee charged on table bills.
 */
class TablesController extends Controller
{
    use InteractsWithCurrentStore;

    public function edit(): Response
    {
        $store = $this->currentStore();

        return Inertia::render('store-settings/Tables', [
            'serviceFeePercent' => $store->service_fee_percent,
            'tables' => $store->diningTables()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->withCount('tabs')
                ->get()
                ->map(fn (DiningTable $table) => [
                    'id' => $table->id,
                    'name' => $table->name,
                    'seats' => $table->seats,
                    'isActive' => $table->is_active,
                    'hasHistory' => $table->getAttribute('tabs_count') > 0,
                ]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service_fee_percent' => ['required', 'integer', 'min:0', 'max:30'],
        ]);

        $this->currentStore()->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Taxa de serviço salva.']);

        return back();
    }

    /**
     * One table by name, or a numbered sequence ("Mesa 1" to "Mesa 10").
     */
    public function store(Request $request): RedirectResponse
    {
        $store = $this->currentStore();

        if ($request->filled('from') || $request->filled('to')) {
            $data = $request->validate([
                'from' => ['required', 'integer', 'min:1', 'max:999'],
                'to' => ['required', 'integer', 'gte:from', 'max:999', fn ($attribute, $value, $fail) => (int) $value - (int) $request->input('from') >= 100 ? $fail('Crie no máximo 100 mesas por vez.') : null],
                'seats' => ['nullable', 'integer', 'min:1', 'max:100'],
            ]);

            $existing = $store->diningTables()->pluck('name')->all();
            $order = (int) $store->diningTables()->max('sort_order');
            $created = 0;

            for ($number = (int) $data['from']; $number <= (int) $data['to']; $number++) {
                $name = "Mesa {$number}";

                if (! in_array($name, $existing, true)) {
                    $store->diningTables()->create(['name' => $name, 'seats' => $data['seats'] ?? null, 'sort_order' => ++$order]);
                    $created++;
                }
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => "{$created} mesa(s) criada(s)."]);

            return back();
        }

        $data = $this->validated($request);
        $store->diningTables()->create([...$data, 'sort_order' => (int) $store->diningTables()->max('sort_order') + 1]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mesa criada.']);

        return back();
    }

    public function updateTable(Request $request, DiningTable $table): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($table);

        $table->update($this->validated($request, $table));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mesa atualizada.']);

        return back();
    }

    public function destroy(DiningTable $table): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($table);

        // Tables with past bills are kept for the reports; they can be deactivated instead.
        if ($table->tabs()->exists()) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Esta mesa já teve contas. Desative-a em vez de excluir.']);

            return back();
        }

        $table->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Mesa excluída.']);

        return back();
    }

    /**
     * @return array{name: string, seats: int|null, is_active: bool}
     */
    private function validated(Request $request, ?DiningTable $table = null): array
    {
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:40',
                Rule::unique('dining_tables', 'name')->where('store_id', $this->currentStore()->id)->ignore($table?->id),
            ],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100'],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Já existe uma mesa com esse nome.']);

        return [
            'name' => trim((string) $data['name']),
            'seats' => isset($data['seats']) ? (int) $data['seats'] : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
