<?php

namespace Tests\Feature\Settings;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StoreContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_edits_whatsapp_and_address(): void
    {
        $store = Store::factory()->create(['whatsapp' => '5542999990000']);
        $owner = User::factory()->create();
        $store->users()->attach($owner, ['role' => StoreRole::Owner->value]);

        $this->actingAs($owner)
            ->get(route('store-settings.contact.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('store-settings/Contact')->where('store.whatsapp', '42999990000'));

        $this->actingAs($owner)->put(route('store-settings.contact.update'), [
            'whatsapp' => '(42) 98888-7777',
            'email' => 'contato@loja.com',
            'address_zip' => '84010-000',
            'address_street' => 'Rua XV de Novembro',
            'address_number' => '450',
            'address_complement' => '',
            'address_district' => 'Centro',
            'address_city' => 'Ponta Grossa',
            'address_state' => 'pr',
        ])->assertSessionHasNoErrors();

        $store->refresh();
        $this->assertSame('5542988887777', $store->whatsapp);
        $this->assertSame('84010000', $store->address_zip);
        $this->assertSame('PR', $store->address_state);
        $this->assertNull($store->address_complement);

        // Staff without "manage-store" cannot change it.
        $attendant = User::factory()->create();
        $store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);
        $this->actingAs($attendant)->put(route('store-settings.contact.update'), ['whatsapp' => '42911112222'])->assertForbidden();
    }
}
