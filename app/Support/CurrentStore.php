<?php

namespace App\Support;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The store the signed-in user is working on in the panel.
 * Resolved once per request and user from the session, falling back to the user's first store.
 */
final class CurrentStore
{
    private ?Store $store = null;

    private ?StoreRole $role = null;

    /** Which request and user the cached store belongs to; anything else resolves again. */
    private ?string $resolvedFor = null;

    public function get(): ?Store
    {
        $request = $this->request();
        $user = $request->user();
        $key = $this->keyFor($request, $user);

        if ($this->resolvedFor === $key) {
            return $this->store;
        }

        $this->resolvedFor = $key;
        $this->store = null;
        $this->role = null;

        if (! $user instanceof User) {
            return null;
        }

        // Support mode: the platform admin sees the chosen store as its owner would.
        $support = $user->is_super_admin === true ? SupportSession::current($request) : null;

        if ($support !== null) {
            $store = Store::query()->find($support['store_id']);

            if ($store instanceof Store) {
                $this->role = StoreRole::Owner;

                return $this->store = $store;
            }
        }

        $selectedId = $request->hasSession() ? $request->session()->get('current_store_id') : null;

        $store = $selectedId !== null
            ? $user->stores()->whereKey($selectedId)->first()
            : null;

        $store ??= $user->stores()->orderBy('stores.id')->first();

        if ($store !== null && $request->hasSession()) {
            $request->session()->put('current_store_id', $store->id);
        }

        $this->role = $store?->roleOf($user);

        return $this->store = $store;
    }

    public function require(): Store
    {
        $store = $this->get();

        abort_if($store === null, 403);

        return $store;
    }

    public function role(): ?StoreRole
    {
        $this->get();

        return $this->role;
    }

    public function switchTo(Store $store): void
    {
        $request = $this->request();
        $user = $request->user();

        $this->store = $store;
        $this->role = $user instanceof User ? $store->roleOf($user) : null;
        $this->resolvedFor = $this->keyFor($request, $user);
        $request->session()->put('current_store_id', $store->id);
    }

    private function keyFor(Request $request, mixed $user): string
    {
        return spl_object_id($request).':'.($user instanceof User ? $user->id : 'guest');
    }

    private function request(): Request
    {
        return app('request');
    }
}
