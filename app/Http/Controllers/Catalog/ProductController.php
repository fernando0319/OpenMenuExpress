<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductRequest;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Support\ImageStorage;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly ImageStorage $images) {}

    public function index(Request $request): Response
    {
        $store = $this->currentStore();
        $categoryId = $request->integer('category') ?: null;
        $search = trim($request->string('search')->toString());

        $products = $store->products()
            ->with('category:id,name')
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('category_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'category' => $product->category->name,
                'price_cents' => $product->price_cents,
                'effective_price_cents' => $product->effectivePriceCents(),
                'is_active' => $product->is_active,
                'is_featured' => $product->is_featured,
                'is_pizza' => $product->isPizza(),
            ]);

        return Inertia::render('catalog/products/Index', [
            'products' => $products,
            'categories' => $store->categories()->orderBy('sort_order')->get(['id', 'name']),
            'filters' => ['category' => $categoryId, 'search' => $search],
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('catalog/products/Form', [
            'product' => null,
            'presetKind' => $request->query('kind') === Product::KIND_PIZZA ? Product::KIND_PIZZA : 'standard',
            ...$this->formOptions(),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $product = $this->currentStore()->products()->create($this->productAttributes($request));
            $this->syncOptionGroups($product, $request->validated('option_groups', []));
            $this->syncImage($request, $product);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produto criado.']);

        return to_route('catalog.products.index');
    }

    public function edit(Product $product): Response
    {
        $this->ensureBelongsToCurrentStore($product);

        return Inertia::render('catalog/products/Form', [
            'presetKind' => $product->kind,
            'product' => [
                'id' => $product->id,
                'category_id' => $product->category_id,
                'kind' => $product->kind,
                'pizza_max_flavors' => $product->pizza_max_flavors,
                'pizza_slices' => $product->pizza_slices,
                'pizza_diameter_cm' => $product->pizza_diameter_cm,
                'name' => $product->name,
                'description' => $product->description,
                'sku' => $product->sku,
                'imageUrl' => ImageStorage::url($product->image_path),
                'price' => Money::toInput($product->price_cents),
                'promotional_price' => Money::toInput($product->promotional_price_cents),
                'is_featured' => $product->is_featured,
                'is_active' => $product->is_active,
                'track_stock' => $product->track_stock,
                'stock_quantity' => $product->stock_quantity,
                'option_groups' => ProductOptionGroup::query()
                    ->where('product_id', $product->id)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (ProductOptionGroup $link) => [
                        'id' => $link->option_group_id,
                        'min_choices' => $link->min_choices,
                        'max_choices' => $link->max_choices,
                    ]),
            ],
            ...$this->formOptions(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($product);

        DB::transaction(function () use ($request, $product): void {
            $product->update($this->productAttributes($request));
            $this->syncOptionGroups($product, $request->validated('option_groups', []));
            $this->syncImage($request, $product);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produto atualizado.']);

        return to_route('catalog.products.index');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($product);

        $product->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Produto excluído.']);

        return to_route('catalog.products.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function productAttributes(ProductRequest $request): array
    {
        $data = $request->safe()->except(['option_groups', 'image', 'remove_image']);

        if (! ($data['track_stock'] ?? false)) {
            $data['stock_quantity'] = null;
        }

        if (($data['kind'] ?? 'standard') !== Product::KIND_PIZZA) {
            $data['pizza_max_flavors'] = $data['pizza_slices'] = $data['pizza_diameter_cm'] = null;
        }

        return $data;
    }

    private function syncImage(ProductRequest $request, Product $product): void
    {
        $file = $request->file('image');

        if ($file instanceof UploadedFile) {
            $this->images->delete($product->image_path);
            $product->update(['image_path' => $this->images->store($file, $this->currentStore(), 'products')]);
        } elseif ($request->boolean('remove_image') && $product->image_path !== null) {
            $this->images->delete($product->image_path);
            $product->update(['image_path' => null]);
        }
    }

    /**
     * @param  array<int, array{id: int, min_choices?: int|null, max_choices?: int|null}>  $groups
     */
    private function syncOptionGroups(Product $product, array $groups): void
    {
        $pivot = [];

        foreach (array_values($groups) as $position => $group) {
            $pivot[$group['id']] = [
                'min_choices' => $group['min_choices'] ?? null,
                'max_choices' => $group['max_choices'] ?? null,
                'sort_order' => $position,
            ];
        }

        $product->optionGroups()->sync($pivot);
    }

    /**
     * @return array{categories: mixed, optionGroups: mixed}
     */
    private function formOptions(): array
    {
        $store = $this->currentStore();

        return [
            'categories' => $store->categories()->orderBy('sort_order')->get(['id', 'name']),
            'optionGroups' => $store->optionGroups()
                ->withCount('options')
                ->orderBy('name')
                ->get(['id', 'name', 'min_choices', 'max_choices']),
        ];
    }
}
