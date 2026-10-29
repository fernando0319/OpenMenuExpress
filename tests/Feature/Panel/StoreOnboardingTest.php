<?php

namespace Tests\Feature\Panel;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_store_is_sent_to_store_creation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('stores.create'));
    }

    public function test_user_creates_first_store_and_becomes_owner(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('stores.store'), [
                'name' => 'Pizzaria do Zé',
                'slug' => '',
                'whatsapp' => '(11) 99999-8888',
            ])
            ->assertRedirect(route('dashboard'));

        $store = Store::query()->sole();
        $this->assertSame('pizzaria-do-ze', $store->slug);
        $this->assertSame('5511999998888', $store->whatsapp);
        $this->assertSame(StoreRole::Owner, $store->roleOf($user));

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_slug_must_be_unique_and_not_reserved(): void
    {
        Store::factory()->create(['slug' => 'lanchonete']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('stores.store'), ['name' => 'Outra', 'slug' => 'lanchonete'])
            ->assertSessionHasErrors('slug');

        $this->actingAs($user)
            ->post(route('stores.store'), ['name' => 'Admin', 'slug' => 'admin'])
            ->assertSessionHasErrors('slug');
    }

    public function test_store_creation_screen_redirects_users_who_already_have_a_store(): void
    {
        $user = User::factory()->create();
        Store::factory()->create()->users()->attach($user, ['role' => StoreRole::Owner->value]);

        $this->actingAs($user)
            ->get(route('stores.create'))
            ->assertRedirect(route('dashboard'));
    }
}
