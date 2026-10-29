<?php

namespace Tests\Feature\CourierNetwork;

use App\Enums\CourierStatus;
use App\Models\NetworkCourier;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\CourierNetwork\Dispatcher;
use App\Services\Push\PushTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PushNotificationsTest extends TestCase
{
    use BuildsNetwork, RefreshDatabase;

    /** @var list<array{users: list<int>, payload: array<string, string>}> */
    private array $sent = [];

    /** @var list<int> */
    private array $expire = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNetwork();

        $test = $this;
        $this->app->instance(PushTransport::class, new class($test) implements PushTransport
        {
            public function __construct(private readonly PushNotificationsTest $test) {}

            public function send(Collection $subscriptions, string $payload): array
            {
                return $this->test->record($subscriptions, $payload);
            }
        });
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @return list<int>
     */
    public function record(Collection $subscriptions, string $payload): array
    {
        /** @var array<string, string> $message */
        $message = json_decode($payload, true);
        $this->sent[] = ['users' => $subscriptions->pluck('user_id')->unique()->values()->all(), 'payload' => $message];

        return array_values(array_intersect($this->expire, $subscriptions->modelKeys()));
    }

    public function test_the_phone_registers_for_notifications(): void
    {
        $courier = $this->courierKmNorth(1);

        $this->actingAs($courier->user)->postJson(route('courier.push.store'), $this->subscription('abc'))->assertOk();
        $this->actingAs($courier->user)->postJson(route('courier.push.store'), $this->subscription('abc'))->assertOk();

        $this->assertSame(1, PushSubscription::query()->count(), 'The same browser is kept once.');
        $this->assertSame($courier->user_id, PushSubscription::query()->sole()->user_id);
    }

    public function test_nearby_couriers_are_woken_up_once_per_run(): void
    {
        $near = $this->subscribed($this->courierKmNorth(1, 'Perto'));
        $far = $this->subscribed($this->courierKmNorth(20, 'Longe'));
        $busy = $this->subscribed($this->courierKmNorth(1, 'Ocupado'));
        app(Dispatcher::class)->accept(app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner), $busy);
        $this->sent = [];

        // The queue runs synchronously in tests: every wave runs at once, and each courier hears once.
        app(Dispatcher::class)->call($this->deliveryOrder(), 1000, $this->owner);

        $this->assertCount(1, $this->sent);
        $this->assertSame([$near->user_id], $this->sent[0]['users']);
        $this->assertSame('Nova corrida · R$ 8,50', $this->sent[0]['payload']['title']);
        $this->assertStringContainsString('Pizzaria do Zé (Centro) → Jardins', $this->sent[0]['payload']['body']);
        $this->assertNotContains($far->user_id, $this->sent[0]['users']);
    }

    public function test_a_phone_off_for_a_while_still_gets_runs_but_not_forever(): void
    {
        $courier = $this->subscribed($this->courierKmNorth(1));
        $courier->update(['located_at' => now()->subMinutes(40)]);

        app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);
        $this->assertCount(1, $this->sent, 'Screen off for 40 minutes: still notified.');

        $courier->update(['located_at' => now()->subHours(3)]);
        $this->artisan('app:expire-courier-presence')->assertSuccessful();

        $this->assertFalse($courier->fresh()?->is_online);
        $this->assertSame('Você ficou offline', $this->sent[1]['payload']['title']);
    }

    public function test_the_courier_hears_when_the_store_cancels_and_when_reviewed(): void
    {
        $courier = $this->subscribed($this->courierKmNorth(1));
        $order = $this->deliveryOrder();
        app(Dispatcher::class)->accept(app(Dispatcher::class)->call($order, 800, $this->owner), $courier);

        $this->actingAs($this->owner)->delete(route('orders.network.destroy', $order))->assertSessionHasNoErrors();
        $this->assertSame('Corrida cancelada', end($this->sent)['payload']['title']);

        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin)->post(route('admin.network.couriers.review', $courier), ['status' => CourierStatus::Blocked->value, 'reason' => 'Teste'])->assertSessionHasNoErrors();
        $this->assertSame('Acesso à rede bloqueado', end($this->sent)['payload']['title']);
    }

    public function test_dead_subscriptions_are_forgotten(): void
    {
        $courier = $this->subscribed($this->courierKmNorth(1));
        $this->expire = [PushSubscription::query()->sole()->id];

        app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);

        $this->assertSame(0, PushSubscription::query()->where('user_id', $courier->user_id)->count());
    }

    private function subscribed(NetworkCourier $courier): NetworkCourier
    {
        $this->actingAs($courier->user)->postJson(route('courier.push.store'), $this->subscription((string) $courier->id))->assertOk();

        return $courier;
    }

    /**
     * @return array<string, mixed>
     */
    private function subscription(string $id): array
    {
        return [
            'endpoint' => "https://fcm.googleapis.com/fcm/send/{$id}",
            'keys' => ['p256dh' => 'BKey'.$id, 'auth' => 'auth'.$id],
            'contentEncoding' => 'aes128gcm',
        ];
    }
}
