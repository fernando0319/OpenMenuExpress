<?php

namespace App\Services\Team;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who works in the store and with which role.
 *
 * Rules: owners manage everyone; managers only attendants and waiters;
 * a store always keeps at least one owner.
 */
final class TeamService
{
    /**
     * Roles the acting user may give to someone.
     *
     * @return list<StoreRole>
     */
    public function assignableRoles(StoreRole $actor): array
    {
        return match ($actor) {
            StoreRole::Owner => StoreRole::cases(),
            StoreRole::Manager => [StoreRole::Attendant, StoreRole::Waiter, StoreRole::Courier],
            default => [],
        };
    }

    public function canManage(StoreRole $actor, StoreRole $target): bool
    {
        return in_array($target, $this->assignableRoles($actor), true);
    }

    /**
     * Adds a person to the store. An e-mail that already has an account joins as is
     * (its password is never touched); otherwise a new account is created, ready to sign in.
     *
     * @param  array{name: string, email: string|null, username: string|null, password: string|null}  $data
     * @return array{user: User, created: bool}
     */
    public function add(Store $store, StoreRole $actor, StoreRole $role, array $data): array
    {
        $this->ensureCanAssign($actor, $role);

        return DB::transaction(function () use ($store, $role, $data): array {
            $email = $data['email'] !== null ? Str::lower(trim($data['email'])) : null;
            $existing = $email !== null ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first() : null;

            if ($existing !== null) {
                if ($store->users()->whereKey($existing->id)->exists()) {
                    $this->fail('email', 'Essa pessoa já faz parte da equipe.');
                }

                $store->users()->attach($existing, ['role' => $role->value]);

                return ['user' => $existing, 'created' => false];
            }

            if ($data['password'] === null) {
                $this->fail('password', 'Defina uma senha para o primeiro acesso.');
            }

            $user = new User;
            $user->forceFill([
                'name' => $data['name'],
                'email' => $email,
                'username' => $data['username'],
                'password' => $data['password'],
                // Created by the store: no e-mail confirmation step to sign in.
                'email_verified_at' => now(),
            ])->save();

            $store->users()->attach($user, ['role' => $role->value]);

            return ['user' => $user, 'created' => true];
        });
    }

    public function changeRole(Store $store, StoreRole $actor, User $member, StoreRole $role): void
    {
        DB::transaction(function () use ($store, $actor, $member, $role): void {
            $current = $this->membership($store, $member);

            $this->ensureCanAssign($actor, $current->role);
            $this->ensureCanAssign($actor, $role);

            if ($current->role === StoreRole::Owner && $role !== StoreRole::Owner) {
                $this->ensureAnotherOwner($store, $member);
            }

            $store->users()->updateExistingPivot($member->id, ['role' => $role->value]);
        });
    }

    public function remove(Store $store, StoreRole $actor, User $member): void
    {
        DB::transaction(function () use ($store, $actor, $member): void {
            $current = $this->membership($store, $member);

            $this->ensureCanAssign($actor, $current->role);

            if ($current->role === StoreRole::Owner) {
                $this->ensureAnotherOwner($store, $member);
            }

            $store->users()->detach($member->id);
        });
    }

    /**
     * New password for someone the store created. Accounts that also work elsewhere or
     * signed up on their own keep control of their password.
     */
    public function resetPassword(Store $store, StoreRole $actor, User $member, string $password): void
    {
        $current = $this->membership($store, $member);
        $this->ensureCanAssign($actor, $current->role);

        if (! $this->canResetPassword($member)) {
            $this->fail('password', 'Essa pessoa usa a conta em outra loja. Ela mesma deve trocar a senha em "Esqueci minha senha".');
        }

        $member->forceFill(['password' => $password])->save();
    }

    public function canResetPassword(User $member): bool
    {
        return $member->stores()->count() === 1 && ! $member->is_super_admin;
    }

    private function membership(Store $store, User $member): StoreMember
    {
        $membership = StoreMember::query()
            ->where('store_id', $store->id)
            ->where('user_id', $member->id)
            ->lockForUpdate()
            ->first();

        if ($membership === null) {
            abort(404);
        }

        return $membership;
    }

    private function ensureAnotherOwner(Store $store, User $member): void
    {
        $owners = StoreMember::query()
            ->where('store_id', $store->id)
            ->where('role', StoreRole::Owner->value)
            ->where('user_id', '!=', $member->id)
            ->lockForUpdate()
            ->count();

        if ($owners === 0) {
            $this->fail('role', 'A loja precisa de pelo menos um dono.');
        }
    }

    private function ensureCanAssign(StoreRole $actor, StoreRole $role): void
    {
        if (! $this->canManage($actor, $role)) {
            abort(403, 'Você não pode gerenciar esse papel.');
        }
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
