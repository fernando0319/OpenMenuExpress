<?php

namespace App\Http\Controllers;

use App\Enums\StoreRole;
use App\Http\Requests\StoreCreateRequest;
use App\Models\Store;
use App\Support\CurrentStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    /**
     * Show the first-store screen shown right after sign-up.
     */
    public function create(Request $request, CurrentStore $currentStore): Response|RedirectResponse
    {
        if ($currentStore->get() !== null) {
            return to_route('dashboard');
        }

        if ($request->user()?->is_super_admin === true) {
            return to_route('admin.dashboard');
        }

        return Inertia::render('onboarding/CreateStore', [
            'baseDomain' => parse_url((string) config('app.url'), PHP_URL_HOST),
        ]);
    }

    /**
     * Create a store owned by the signed-in user and make it the current one.
     */
    public function store(StoreCreateRequest $request, CurrentStore $currentStore): RedirectResponse
    {
        $store = DB::transaction(function () use ($request): Store {
            $store = Store::create($request->validated());
            $store->users()->attach($request->user(), ['role' => StoreRole::Owner->value]);

            return $store;
        });

        $currentStore->switchTo($store);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Loja criada! Agora cadastre seu cardápio.']);

        return to_route('dashboard');
    }

    /**
     * For people who work in more than one store: choose which one the panel shows.
     */
    public function switch(Request $request, Store $store, CurrentStore $currentStore): RedirectResponse
    {
        abort_unless($request->user()?->stores()->whereKey($store->id)->exists(), 404);

        $currentStore->switchTo($store);

        return to_route('dashboard');
    }
}
