<?php

namespace App\Http\Controllers\Finance;

use App\Enums\FinanceType;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\SupplierRequest;
use App\Models\FinanceInstallment;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(Request $request): Response
    {
        $store = $this->currentStore();
        $search = trim((string) $request->query('q'));

        // What is still owed to each supplier.
        $open = FinanceInstallment::query()
            ->where('finance_installments.store_id', $store->id)
            ->open()
            ->join('finance_titles', 'finance_titles.id', '=', 'finance_installments.title_id')
            ->where('finance_titles.type', FinanceType::Payable->value)
            ->whereNotNull('finance_titles.supplier_id')
            ->groupBy('finance_titles.supplier_id')
            ->selectRaw('finance_titles.supplier_id as supplier_id, sum(finance_installments.amount_cents) as cents')
            ->toBase()
            ->pluck('cents', 'supplier_id');

        $suppliers = $store->suppliers()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
                $digits = preg_replace('/\D/', '', $search) ?? '';
                $query->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->when($digits !== '', fn (Builder $byDigits) => $byDigits->orWhere('document', 'like', "%{$digits}%")->orWhere('phone', 'like', "%{$digits}%")));
            })
            ->withCount('titles')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'document' => $supplier->document,
                'documentLabel' => $supplier->formattedDocument(),
                'contactName' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'notes' => $supplier->notes,
                'isActive' => $supplier->is_active,
                'titlesCount' => (int) $supplier->getAttribute('titles_count'),
                'openCents' => (int) ($open[$supplier->id] ?? 0),
            ]);

        return Inertia::render('finance/Suppliers', [
            'suppliers' => $suppliers,
            'search' => $search,
        ]);
    }

    public function store(SupplierRequest $request): RedirectResponse
    {
        $this->currentStore()->suppliers()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fornecedor cadastrado.']);

        return back();
    }

    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($supplier);
        $supplier->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fornecedor atualizado.']);

        return back();
    }

    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($supplier);

        if ($supplier->titles()->exists()) {
            $supplier->update(['is_active' => false]);
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Este fornecedor tem lançamentos, então foi desativado em vez de excluído.']);

            return back();
        }

        $supplier->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Fornecedor excluído.']);

        return back();
    }
}
