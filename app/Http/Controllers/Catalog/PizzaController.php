<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\FlavorKind;
use App\Enums\PizzaPricing;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\PizzaCrust;
use App\Models\PizzaFlavor;
use App\Models\PizzaFlavorGroup;
use App\Models\Product;
use App\Support\ImageStorage;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pizzeria mode: the store's flavor groups with a price per size, the flavors, the crusts
 * and the pricing rules. The sizes themselves are products of kind "pizza".
 */
class PizzaController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly ImageStorage $images) {}

    public function index(): Response
    {
        $store = $this->currentStore();
        $sizes = $this->sizes();
        $toInputs = fn (array $prices) => collect($sizes)->mapWithKeys(fn (array $size) => [
            $size['id'] => isset($prices[$size['id']]) ? Money::toInput((int) $prices[$size['id']]) : '',
        ]);

        return Inertia::render('catalog/Pizzas', [
            'settings' => ['pricing' => $store->pizza_pricing->value, 'mixKinds' => $store->pizza_mix_kinds],
            'pricingOptions' => array_map(fn (PizzaPricing $rule) => ['value' => $rule->value, 'label' => $rule->label()], PizzaPricing::cases()),
            'kinds' => array_map(fn (FlavorKind $kind) => ['value' => $kind->value, 'label' => $kind->label()], FlavorKind::cases()),
            'sizes' => $sizes,
            'groups' => $store->pizzaFlavorGroups()->with('flavors')->get()->map(fn (PizzaFlavorGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'kind' => $group->kind->value,
                'isActive' => $group->is_active,
                'prices' => $toInputs($group->prices),
                'flavors' => $group->flavors->map(fn (PizzaFlavor $flavor) => [
                    'id' => $flavor->id,
                    'name' => $flavor->name,
                    'description' => $flavor->description,
                    'imageUrl' => ImageStorage::url($flavor->image_path),
                    'extra' => $flavor->extra_cents > 0 ? Money::toInput($flavor->extra_cents) : '',
                    'unavailable' => array_map('intval', $flavor->unavailable_product_ids ?? []),
                    'isActive' => $flavor->is_active,
                ])->values(),
            ])->values(),
            'crusts' => $store->pizzaCrusts()->get()->map(fn (PizzaCrust $crust) => [
                'id' => $crust->id,
                'name' => $crust->name,
                'description' => $crust->description,
                'isActive' => $crust->is_active,
                'prices' => $toInputs($crust->prices),
            ])->values(),
        ]);
    }

    public function settings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'pricing' => ['required', Rule::enum(PizzaPricing::class)],
            'mix_kinds' => ['required', 'boolean'],
        ]);

        $this->currentStore()->update(['pizza_pricing' => $data['pricing'], 'pizza_mix_kinds' => $data['mix_kinds']]);
        $this->toast('Regras das pizzas salvas.');

        return back();
    }

    /**
     * The whole price table at once: groups × sizes and crusts × sizes. An empty cell means
     * "not sold in this size".
     */
    public function prices(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'groups' => ['array'],
            'groups.*' => ['array'],
            'crusts' => ['array'],
            'crusts.*' => ['array'],
        ]);

        $store = $this->currentStore();
        $sizeIds = array_column($this->sizes(), 'id');
        $groups = $store->pizzaFlavorGroups()->get()->keyBy('id');
        $crusts = $store->pizzaCrusts()->get()->keyBy('id');
        $updates = [];

        foreach (['groups' => $groups, 'crusts' => $crusts] as $key => $models) {
            foreach ($data[$key] ?? [] as $id => $cells) {
                $model = $models->get((int) $id);

                if ($model === null || ! is_array($cells)) {
                    continue;
                }

                $updates[] = [$model, $this->parsePrices($cells, $sizeIds, "{$key}.{$id}")];
            }
        }

        DB::transaction(function () use ($updates): void {
            foreach ($updates as [$model, $prices]) {
                $model->update(['prices' => $prices]);
            }
        });

        $this->toast('Tabela de preços salva.');

        return back();
    }

    /**
     * Raises (or lowers) every price of the table by a percentage, rounded to the cent.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'percent' => ['required', 'numeric', 'between:-50,200', 'not_in:0'],
            'target' => ['required', Rule::in(['all', 'groups', 'crusts'])],
        ], ['percent.not_in' => 'Informe um percentual diferente de zero.']);

        $store = $this->currentStore();
        $factor = 1 + ((float) $data['percent']) / 100;
        $models = collect();

        if ($data['target'] !== 'crusts') {
            $models = $models->merge($store->pizzaFlavorGroups()->get());
        }

        if ($data['target'] !== 'groups') {
            $models = $models->merge($store->pizzaCrusts()->get());
        }

        DB::transaction(function () use ($models, $factor): void {
            foreach ($models as $model) {
                $model->update(['prices' => array_map(fn (int $cents) => (int) round($cents * $factor), $model->prices)]);
            }
        });

        $this->toast('Preços reajustados em '.str_replace('.', ',', (string) $data['percent']).'%.');

        return back();
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $data = $this->groupData($request);
        $store = $this->currentStore();
        $store->pizzaFlavorGroups()->create([...$data, 'prices' => [], 'sort_order' => (int) $store->pizzaFlavorGroups()->max('sort_order') + 1]);
        $this->toast('Categoria de sabores criada. Preencha os preços na tabela.');

        return back();
    }

    public function updateGroup(Request $request, PizzaFlavorGroup $group): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($group);
        $group->update($this->groupData($request));
        $this->toast('Categoria atualizada.');

        return back();
    }

    public function destroyGroup(PizzaFlavorGroup $group): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($group);

        foreach ($group->flavors as $flavor) {
            $this->images->delete($flavor->image_path);
        }

        // Orders keep the flavor names they were sold with.
        $group->delete();
        $this->toast('Categoria e seus sabores excluídos.');

        return back();
    }

    public function storeFlavor(Request $request): RedirectResponse
    {
        $store = $this->currentStore();
        $data = $this->flavorData($request);
        $flavor = $store->pizzaFlavors()->create([...$data, 'sort_order' => (int) $store->pizzaFlavors()->where('group_id', $data['group_id'])->max('sort_order') + 1]);
        $this->syncImage($request, $flavor);
        $this->toast('Sabor cadastrado.');

        return back();
    }

    public function updateFlavor(Request $request, PizzaFlavor $flavor): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($flavor);
        $flavor->update($this->flavorData($request));
        $this->syncImage($request, $flavor);
        $this->toast('Sabor atualizado.');

        return back();
    }

    /**
     * "Acabou": pauses the flavor in every size at once (and back).
     */
    public function toggleFlavor(PizzaFlavor $flavor): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($flavor);
        $flavor->update(['is_active' => ! $flavor->is_active]);
        $this->toast($flavor->is_active ? "{$flavor->name} voltou ao cardápio." : "{$flavor->name} pausado em todos os tamanhos.");

        return back();
    }

    public function destroyFlavor(PizzaFlavor $flavor): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($flavor);
        $this->images->delete($flavor->image_path);
        $flavor->delete();
        $this->toast('Sabor excluído.');

        return back();
    }

    public function storeCrust(Request $request): RedirectResponse
    {
        $store = $this->currentStore();
        $store->pizzaCrusts()->create([...$this->crustData($request), 'prices' => [], 'sort_order' => (int) $store->pizzaCrusts()->max('sort_order') + 1]);
        $this->toast('Borda criada. Preencha os preços na tabela.');

        return back();
    }

    public function updateCrust(Request $request, PizzaCrust $crust): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($crust);
        $crust->update($this->crustData($request));
        $this->toast('Borda atualizada.');

        return back();
    }

    public function destroyCrust(PizzaCrust $crust): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($crust);
        $crust->delete();
        $this->toast('Borda excluída.');

        return back();
    }

    /**
     * The pizza sizes of the store, in menu order.
     *
     * @return list<array{id: int, name: string, maxFlavors: int, slices: int|null, diameterCm: int|null, isActive: bool}>
     */
    private function sizes(): array
    {
        return array_values($this->currentStore()->products()
            ->where('kind', Product::KIND_PIZZA)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'maxFlavors' => (int) $product->pizza_max_flavors,
                'slices' => $product->pizza_slices,
                'diameterCm' => $product->pizza_diameter_cm,
                'isActive' => $product->is_active,
            ])
            ->all());
    }

    /**
     * @param  array<mixed>  $cells  size id => "55,00" | ""
     * @param  list<int>  $sizeIds
     * @return array<int, int>
     */
    private function parsePrices(array $cells, array $sizeIds, string $errorKey): array
    {
        $prices = [];

        foreach ($cells as $sizeId => $value) {
            if (! in_array((int) $sizeId, $sizeIds, true) || $value === null || $value === '') {
                continue;
            }

            $cents = Money::parseToCents($value);

            if ($cents === null) {
                throw ValidationException::withMessages(["{$errorKey}.{$sizeId}" => 'Preço inválido. Use o formato 55,00.']);
            }

            $prices[(int) $sizeId] = $cents;
        }

        return $prices;
    }

    /**
     * @return array{name: string, kind: string, is_active: bool}
     */
    private function groupData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'kind' => ['required', Rule::enum(FlavorKind::class)],
            'is_active' => ['boolean'],
        ], ['name.required' => 'Informe o nome (ex.: Salgadas Tradicionais).']);

        return ['name' => $data['name'], 'kind' => $data['kind'], 'is_active' => (bool) ($data['is_active'] ?? true)];
    }

    /**
     * @return array{group_id: int, name: string, description: string|null, extra_cents: int, unavailable_product_ids: list<int>, is_active: bool}
     */
    private function flavorData(Request $request): array
    {
        $store = $this->currentStore();
        $extra = $request->input('extra');
        $request->merge(['extra' => $extra === null || $extra === '' ? 0 : (Money::parseToCents($extra) ?? 'invalid')]);

        $data = $request->validate([
            'group_id' => ['required', 'integer', Rule::exists('pizza_flavor_groups', 'id')->where('store_id', $store->id)],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'extra' => ['integer', 'min:0', 'max:1000000'],
            'unavailable' => ['array'],
            'unavailable.*' => ['integer'],
            'is_active' => ['boolean'],
            'image' => ['nullable', ...ImageStorage::rules()],
            'remove_image' => ['boolean'],
        ], [
            'name.required' => 'Informe o nome do sabor.',
            'extra.integer' => 'Acréscimo inválido. Use o formato 5,00.',
        ]);

        $sizeIds = array_column($this->sizes(), 'id');

        return [
            'group_id' => (int) $data['group_id'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'extra_cents' => (int) $data['extra'],
            'unavailable_product_ids' => array_values(array_intersect(array_map('intval', $data['unavailable'] ?? []), $sizeIds)),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /**
     * @return array{name: string, description: string|null, is_active: bool}
     */
    private function crustData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:200'],
            'is_active' => ['boolean'],
        ], ['name.required' => 'Informe o nome da borda.']);

        return ['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? true)];
    }

    private function syncImage(Request $request, PizzaFlavor $flavor): void
    {
        $file = $request->file('image');

        if ($file instanceof UploadedFile) {
            $this->images->delete($flavor->image_path);
            $flavor->update(['image_path' => $this->images->store($file, $this->currentStore(), 'pizzas')]);
        } elseif ($request->boolean('remove_image') && $flavor->image_path !== null) {
            $this->images->delete($flavor->image_path);
            $flavor->update(['image_path' => null]);
        }
    }

    private function toast(string $message): void
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);
    }
}
