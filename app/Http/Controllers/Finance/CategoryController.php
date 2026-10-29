<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinanceType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Models\FinanceCategory;
use App\Services\Finance\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The store's own expense and income categories (chart of accounts, simplified).
 */
class CategoryController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(FinanceService $finance): Response
    {
        $store = $this->currentStore();
        $finance->ensureCategories($store);

        $categories = $store->financeCategories()->withCount('titles')->orderBy('name')->get();

        $rows = fn (FinanceType $type) => $categories
            ->filter(fn (FinanceCategory $category) => $category->type === $type)
            ->map(fn (FinanceCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'isActive' => $category->is_active,
                'titlesCount' => (int) $category->getAttribute('titles_count'),
            ])
            ->values();

        return Inertia::render('finance/Categories', [
            'payable' => $rows(FinanceType::Payable),
            'receivable' => $rows(FinanceType::Receivable),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $store = $this->currentStore();
        $data = $request->validate([
            'type' => ['required', Rule::enum(FinanceType::class)],
            'name' => ['required', 'string', 'max:60', Rule::unique('finance_categories')->where('store_id', $store->id)->where('type', $request->input('type'))],
        ], ['name.unique' => 'Já existe uma categoria com este nome.', 'name.required' => 'Informe o nome.']);

        $store->financeCategories()->create($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria criada.']);

        return back();
    }

    public function update(Request $request, FinanceCategory $category): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($category);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('finance_categories')->where('store_id', $category->store_id)->where('type', $category->type->value)->ignore($category->id)],
            'is_active' => ['boolean'],
        ], ['name.unique' => 'Já existe uma categoria com este nome.', 'name.required' => 'Informe o nome.']);

        $category->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria atualizada.']);

        return back();
    }

    public function destroy(FinanceCategory $category): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($category);

        if ($category->titles()->exists()) {
            $category->update(['is_active' => false]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria em uso: foi desativada para novos lançamentos.']);

            return back();
        }

        $category->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria excluída.']);

        return back();
    }
}
