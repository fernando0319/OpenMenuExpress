<?php

namespace App\Http\Controllers\Catalog;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(): Response
    {
        $categories = $this->currentStore()->categories()
            ->withCount('products')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active', 'sort_order']);

        return Inertia::render('catalog/Categories', [
            'categories' => $categories,
        ]);
    }

    public function store(CategoryRequest $request): RedirectResponse
    {
        $store = $this->currentStore();

        $store->categories()->create([
            ...$request->validated(),
            'sort_order' => (int) $store->categories()->max('sort_order') + 1,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria criada.']);

        return to_route('catalog.categories.index');
    }

    public function update(CategoryRequest $request, Category $category): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($category);

        $category->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria atualizada.']);

        return to_route('catalog.categories.index');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($category);

        if ($category->products()->withTrashed()->exists()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => 'Mova ou exclua os produtos desta categoria antes de excluí-la.',
            ]);

            return to_route('catalog.categories.index');
        }

        $category->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria excluída.']);

        return to_route('catalog.categories.index');
    }
}
