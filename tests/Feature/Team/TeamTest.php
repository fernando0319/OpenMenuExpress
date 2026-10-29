<?php

namespace Tests\Feature\Team;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['name' => 'Pizzaria do Zé']);
        $this->owner = User::factory()->create(['email' => 'ze@example.com']);
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_owner_adds_a_waiter_who_signs_in_with_a_username(): void
    {
        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), [
                'name' => 'João Garçom',
                'username' => ' Joao.Garcom ',
                'password' => 'segredo123',
                'role' => 'waiter',
            ])
            ->assertSessionHasNoErrors();

        $waiter = User::query()->where('username', 'joao.garcom')->sole();
        $this->assertNull($waiter->email);
        $this->assertNotNull($waiter->email_verified_at);
        $this->assertSame(StoreRole::Waiter, $this->store->roleOf($waiter));

        auth()->logout();

        $this->post(route('login.store'), ['email' => 'JOAO.GARCOM', 'password' => 'errada'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.store'), ['email' => 'joao.garcom', 'password' => 'segredo123']);
        $this->assertAuthenticatedAs($waiter);

        $this->get(route('dashboard'))->assertRedirect(route('tables.index'));
        $this->get(route('tables.index'))->assertOk();
    }

    public function test_e_mail_login_keeps_working_and_ignores_case(): void
    {
        $this->post(route('login.store'), ['email' => 'ZE@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs($this->owner);
    }

    public function test_an_existing_account_joins_without_touching_its_password(): void
    {
        $existing = User::factory()->create(['email' => 'maria@example.com']);
        $originalHash = $existing->password;

        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), ['name' => 'Outro nome', 'email' => 'Maria@example.com', 'password' => 'trocada123', 'role' => 'attendant'])
            ->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame($originalHash, $existing->password);
        $this->assertSame(StoreRole::Attendant, $this->store->roleOf($existing));

        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), ['name' => 'Maria', 'email' => 'maria@example.com', 'role' => 'waiter'])
            ->assertSessionHasErrors('email');
    }

    public function test_login_must_be_valid_and_new_accounts_need_a_password(): void
    {
        User::factory()->create(['username' => 'ana']);

        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), ['name' => 'Sem login', 'password' => 'segredo123', 'role' => 'waiter'])
            ->assertSessionHasErrors('email');
        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), ['name' => 'Ana', 'username' => 'ana', 'password' => 'segredo123', 'role' => 'waiter'])
            ->assertSessionHasErrors('username');
        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), ['name' => 'Bia', 'username' => 'bia silva', 'password' => 'segredo123', 'role' => 'waiter'])
            ->assertSessionHasErrors('username');
        $this->actingAs($this->owner)
            ->post(route('store-settings.team.store'), ['name' => 'Bia', 'username' => 'bia', 'role' => 'waiter'])
            ->assertSessionHasErrors('password');

        $this->assertSame(1, $this->store->users()->count());
    }

    public function test_managers_only_handle_attendants_waiters_and_couriers(): void
    {
        $manager = $this->member(StoreRole::Manager);
        $waiter = $this->member(StoreRole::Waiter);

        $this->actingAs($manager)
            ->post(route('store-settings.team.store'), ['name' => 'Chefe', 'username' => 'chefe', 'password' => 'segredo123', 'role' => 'manager'])
            ->assertForbidden();
        $this->actingAs($manager)->put(route('store-settings.team.update', $this->owner), ['role' => 'waiter'])->assertForbidden();
        $this->actingAs($manager)->delete(route('store-settings.team.destroy', $this->owner))->assertForbidden();

        $this->actingAs($manager)->put(route('store-settings.team.update', $waiter), ['role' => 'attendant'])->assertSessionHasNoErrors();
        $this->assertSame(StoreRole::Attendant, $this->store->roleOf($waiter));

        $this->actingAs($manager)
            ->get(route('store-settings.team.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('store-settings/Team')
                ->has('members', 3)
                ->where('members.0.role', 'owner')
                ->where('members.0.canManage', false)
                ->has('roles', 3));
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $this->actingAs($this->owner)->put(route('store-settings.team.update', $this->owner), ['role' => 'waiter'])->assertSessionHasErrors('member');
        $this->actingAs($this->owner)->delete(route('store-settings.team.destroy', $this->owner))->assertSessionHasErrors('member');

        $this->assertSame(StoreRole::Owner, $this->store->roleOf($this->owner));
    }

    public function test_removed_members_lose_access_right_away(): void
    {
        $waiter = $this->member(StoreRole::Waiter);
        $this->actingAs($waiter)->get(route('tables.index'))->assertOk();

        $this->actingAs($this->owner)->delete(route('store-settings.team.destroy', $waiter))->assertSessionHasNoErrors();

        $this->actingAs($waiter)->get(route('tables.index'))->assertRedirect(route('stores.create'));
        $this->assertNotNull($waiter->fresh()); // the account stays, so past orders keep their author
    }

    public function test_password_reset_only_for_accounts_that_belong_to_this_store_alone(): void
    {
        $waiter = $this->member(StoreRole::Waiter);
        $shared = $this->member(StoreRole::Attendant);
        Store::factory()->create()->users()->attach($shared, ['role' => StoreRole::Owner->value]);

        $this->actingAs($this->owner)->put(route('store-settings.team.password', $waiter), ['password' => 'novasenha1'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('novasenha1', (string) $waiter->fresh()?->password));

        $this->actingAs($this->owner)->put(route('store-settings.team.password', $shared), ['password' => 'novasenha1'])->assertSessionHasErrors('password');
        $this->assertFalse(Hash::check('novasenha1', (string) $shared->fresh()?->password));
    }

    public function test_attendants_and_waiters_cannot_open_the_team_screen(): void
    {
        $this->actingAs($this->member(StoreRole::Attendant))->get(route('store-settings.team.index'))->assertForbidden();
        $this->actingAs($this->member(StoreRole::Waiter))->get(route('store-settings.team.index'))->assertForbidden();
    }

    public function test_people_in_two_stores_switch_between_them(): void
    {
        $second = Store::factory()->create(['name' => 'Filial Centro']);
        $second->users()->attach($this->owner, ['role' => StoreRole::Manager->value]);
        $stranger = Store::factory()->create();

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentStore.name', 'Pizzaria do Zé')
                ->where('currentStore.otherStores.0.name', 'Filial Centro'));

        $this->actingAs($this->owner)->post(route('stores.switch', $second))->assertRedirect(route('dashboard'));

        $this->actingAs($this->owner)
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('currentStore.name', 'Filial Centro')->where('currentStore.role', 'manager'));

        $this->actingAs($this->owner)->post(route('stores.switch', $stranger))->assertNotFound();
    }

    public function test_username_accounts_save_their_profile_without_an_e_mail(): void
    {
        $waiter = User::factory()->create(['email' => null, 'username' => 'carlos']);
        $this->store->users()->attach($waiter, ['role' => StoreRole::Waiter->value]);

        $this->actingAs($waiter)
            ->patch(route('profile.update'), ['name' => 'Carlos Souza', 'email' => ''])
            ->assertSessionHasNoErrors();

        $waiter->refresh();
        $this->assertSame('Carlos Souza', $waiter->name);
        $this->assertNotNull($waiter->email_verified_at);
    }

    private function member(StoreRole $role): User
    {
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role' => $role->value]);

        return $user;
    }
}
