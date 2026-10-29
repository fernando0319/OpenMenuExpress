<?php

namespace App\Support;

use App\Enums\StoreRole;
use App\Models\StoreMember;
use App\Models\User;
use Illuminate\Validation\Rules\Password;

/**
 * Which password rule applies to whom. People who manage a store or the platform keep the
 * strong rule (Password::defaults()). Staff and couriers, who sign in on shared or small
 * screens all day, get a lighter one: at least 8 characters with letters and numbers.
 */
final class PasswordPolicy
{
    private const MANAGING_ROLES = [StoreRole::Owner, StoreRole::Manager];

    public static function strong(): Password
    {
        return Password::defaults();
    }

    public static function light(): Password
    {
        // Outside production the strong rule is already this simple; keep both alike there.
        return app()->isProduction() ? Password::min(8)->letters()->numbers() : Password::defaults();
    }

    /**
     * The rule for someone who already has an account (null: a new store owner signing up).
     */
    public static function forUser(?User $user): Password
    {
        if ($user === null || $user->is_super_admin) {
            return self::strong();
        }

        $memberships = StoreMember::query()->where('user_id', $user->id);

        if ((clone $memberships)->whereIn('role', array_map(fn (StoreRole $role) => $role->value, self::MANAGING_ROLES))->exists()) {
            return self::strong();
        }

        // Staff of a store, or a network courier. Anyone else is an owner still creating the store.
        return $memberships->exists() || $user->networkCourier()->exists() ? self::light() : self::strong();
    }

    public static function forRole(StoreRole $role): Password
    {
        return in_array($role, self::MANAGING_ROLES, true) ? self::strong() : self::light();
    }
}
