<?php

namespace Tests\Feature\Settings;

use App\Enums\StoreRole;
use App\Models\NetworkCourier;
use App\Models\Store;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'minlength: 12; required: lower; required: upper; required: digit; required: special;';

    private const LIGHT = 'minlength: 8; required: lower; required: digit;';

    protected function setUp(): void
    {
        parent::setUp();

        // The rules differ only in production (elsewhere both are a plain minimum).
        $this->app['env'] = 'production';
    }

    public function test_managers_keep_the_strong_rule_and_staff_and_couriers_get_the_light_one(): void
    {
        $store = Store::factory()->create();
        $owner = $this->member($store, StoreRole::Owner);
        $manager = $this->member($store, StoreRole::Manager);
        $waiter = $this->member($store, StoreRole::Waiter);
        $courier = NetworkCourier::factory()->create()->user;

        $this->assertSame(self::STRONG, PasswordPolicy::forUser($owner)->toPasswordRulesString());
        $this->assertSame(self::STRONG, PasswordPolicy::forUser($manager)->toPasswordRulesString());
        $this->assertSame(self::LIGHT, PasswordPolicy::forUser($waiter)->toPasswordRulesString());
        $this->assertSame(self::LIGHT, PasswordPolicy::forUser($courier)->toPasswordRulesString());
        $this->assertSame(self::STRONG, PasswordPolicy::forUser(User::factory()->create(['is_super_admin' => true]))->toPasswordRulesString());
        $this->assertSame(self::STRONG, PasswordPolicy::forUser(null)->toPasswordRulesString(), 'A new owner signing up.');

        $this->assertSame(self::LIGHT, PasswordPolicy::forRole(StoreRole::Courier)->toPasswordRulesString());
        $this->assertSame(self::STRONG, PasswordPolicy::forRole(StoreRole::Manager)->toPasswordRulesString());
    }

    private function member(Store $store, StoreRole $role): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
