<?php

namespace Tests\Feature\Panel;

use App\Enums\StoreRole;
use App\Messaging\Evolution\EvolutionClient;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsAppConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://evolution.test';

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'messaging.driver' => 'evolution',
            'messaging.evolution.url' => 'evolution.test', // without scheme on purpose
            'messaging.evolution.key' => 'secret',
            'messaging.evolution.instance_prefix' => 'mx-',
        ]);

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_connect_creates_the_prefixed_instance_and_shows_the_qr_code(): void
    {
        Http::fake([
            self::BASE.'/instance/connectionState/mx-pizzaria-ze' => Http::sequence()
                ->push(['response' => ['message' => ['not found']]], 404)
                ->push(['instance' => ['instanceName' => 'mx-pizzaria-ze', 'state' => 'connecting']]),
            self::BASE.'/instance/create' => Http::response([
                'instance' => ['instanceName' => 'mx-pizzaria-ze', 'status' => 'connecting'],
                'qrcode' => ['base64' => 'data:image/png;base64,QRDATA', 'pairingCode' => null],
            ], 201),
        ]);

        $this->actingAs($this->owner)
            ->post(route('store-settings.messages.whatsapp.connect'))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('store-settings.messages.edit'));

        $this->assertSame('mx-pizzaria-ze', $this->store->fresh()?->whatsapp_instance);

        Http::assertSent(fn (Request $request) => $request->url() === self::BASE.'/instance/create'
            && $request['instanceName'] === 'mx-pizzaria-ze'
            && $request->hasHeader('apikey', 'secret'));

        $this->actingAs($this->owner)
            ->get(route('store-settings.messages.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.state', 'connecting')
                ->where('connection.qr', 'data:image/png;base64,QRDATA'));
    }

    public function test_pairing_code_is_requested_with_the_phone_number(): void
    {
        Http::fake([
            self::BASE.'/instance/connectionState/mx-pizzaria-ze' => Http::response(['instance' => ['state' => 'close']]),
            self::BASE.'/instance/connect/mx-pizzaria-ze*' => Http::response(['pairingCode' => 'ABCD1234', 'code' => 'x']),
        ]);

        $this->actingAs($this->owner)
            ->post(route('store-settings.messages.whatsapp.connect'), ['phone' => '(11) 99999-0000'])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/instance/connect/mx-pizzaria-ze?number=5511999990000'));
    }

    public function test_connected_state_shows_the_number_and_enables_sending(): void
    {
        Http::fake([
            self::BASE.'/instance/connectionState/mx-pizzaria-ze' => Http::response(['instance' => ['state' => 'open']]),
            self::BASE.'/instance/fetchInstances*' => Http::response([[
                'name' => 'mx-pizzaria-ze',
                'ownerJid' => '5511999990000@s.whatsapp.net',
                'profileName' => 'Pizzaria do Zé',
            ]]),
        ]);

        $this->actingAs($this->owner)
            ->get(route('store-settings.messages.edit'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connection.state', 'open')
                ->where('connection.number', '5511999990000')
                ->where('providerReady', true));

        $this->assertSame('mx-pizzaria-ze', $this->store->fresh()?->whatsapp_instance);
    }

    public function test_disconnect_logs_out_and_clears_the_instance(): void
    {
        $this->store->update(['whatsapp_instance' => 'mx-pizzaria-ze']);
        Http::fake([self::BASE.'/instance/logout/mx-pizzaria-ze' => Http::response(['status' => 'SUCCESS'])]);

        $this->actingAs($this->owner)
            ->delete(route('store-settings.messages.whatsapp.disconnect'))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->store->fresh()?->whatsapp_instance);
    }

    public function test_server_errors_are_shown_instead_of_crashing(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('boom', 500)]);

        $this->actingAs($this->owner)
            ->post(route('store-settings.messages.whatsapp.connect'))
            ->assertSessionHasErrors('connection');
    }

    public function test_client_refuses_instances_outside_the_prefix(): void
    {
        $client = app(EvolutionClient::class);

        $this->expectException(InvalidArgumentException::class);

        $client->logout('12');
    }

    public function test_instance_name_cannot_be_set_through_the_settings_form(): void
    {
        $this->actingAs($this->owner)->put(route('store-settings.messages.update'), [
            'whatsapp_enabled' => true,
            'whatsapp_instance' => 'outra-loja',
        ]);

        $this->assertNull($this->store->fresh()?->whatsapp_instance);
        $this->assertTrue($this->store->fresh()->whatsapp_enabled);
    }
}
