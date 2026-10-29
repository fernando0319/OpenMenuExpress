<?php

namespace Tests\Feature\CourierNetwork;

use App\Enums\NetworkDeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\CourierLocation;
use App\Models\DeliveryBill;
use App\Models\NetworkDelivery;
use App\Models\User;
use App\Services\CourierNetwork\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NetworkDispatchTest extends TestCase
{
    use BuildsNetwork, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpNetwork();
    }

    public function test_a_run_from_the_store_call_to_the_doorstep(): void
    {
        $order = $this->deliveryOrder();
        $courier = $this->courierKmNorth(1);

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('network.blockedReason', null)
                ->where('network.defaultFeeCents', 800));

        $this->actingAs($this->owner)
            ->post(route('orders.network.store', $order), ['fee_cents' => 1000])
            ->assertSessionHasNoErrors();

        $delivery = NetworkDelivery::query()->sole();
        $this->assertSame(NetworkDeliveryStatus::Searching, $delivery->status);
        $this->assertSame(850, $delivery->courier_cents, '15% stays with the platform.');

        // The courier sees the run, with what to charge at the door, but not the customer's address yet.
        $this->actingAs($courier->user)
            ->get(route('courier.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('courier/Home')
                ->has('offers', 1)
                ->where('offers.0.storeName', 'Pizzaria do Zé')
                ->where('offers.0.courierCents', 850)
                ->where('offers.0.dropDistrict', 'Jardins')
                ->where('offers.0.collect.amountCents', 4700)
                ->missing('offers.0.address')
                ->where('active', null));

        $this->actingAs($courier->user)->post(route('courier.runs.accept', $delivery))->assertSessionHasNoErrors();
        $this->assertSame($courier->user_id, $order->fresh()?->courier_id);

        $this->actingAs($courier->user)
            ->get(route('courier.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('active.status', 'accepted')
                ->where('active.order.address', 'Rua das Flores, 120')
                ->where('active.order.collect.changeForCents', 5000)
                ->where('offers', []));

        $this->actingAs($this->owner)
            ->get(route('orders.show', $order))
            ->assertInertia(fn (Assert $page) => $page
                ->where('network.delivery.status', 'accepted')
                ->where('network.delivery.courier.name', 'Carlos Motoboy'));

        $this->actingAs($courier->user)->post(route('courier.runs.pick-up', $delivery))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::OutForDelivery, $order->fresh()?->status);

        // On the way, the position also feeds the customer's tracking page.
        $this->actingAs($courier->user)
            ->postJson(route('courier.location'), ['latitude' => -23.56, 'longitude' => -46.64, 'accuracy' => 10])
            ->assertOk();
        $this->assertSame(1, CourierLocation::query()->where('store_id', $this->store->id)->where('user_id', $courier->user_id)->count());

        $this->actingAs($courier->user)->post(route('courier.runs.complete', $delivery))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $order->fresh()?->status);
        $this->assertSame(NetworkDeliveryStatus::Delivered, $delivery->fresh()?->status);

        $this->actingAs($courier->user)
            ->get(route('courier.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('active', null)
                ->where('today.count', 1)
                ->where('today.amountCents', 850));
    }

    public function test_nearby_couriers_get_the_first_chance_and_the_circle_grows(): void
    {
        $near = $this->courierKmNorth(2, 'Perto');
        $middle = $this->courierKmNorth(4, 'Meio');
        $far = $this->courierKmNorth(7, 'Longe');
        $outside = $this->courierKmNorth(12, 'Fora');

        $this->travelTo(now()->startOfMinute());
        $delivery = app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);
        $dispatcher = app(Dispatcher::class);

        $this->assertCount(1, $dispatcher->offersFor($near->fresh() ?? $near));
        $this->assertCount(0, $dispatcher->offersFor($middle->fresh() ?? $middle));

        $this->travel(40)->seconds();
        $this->assertCount(1, $dispatcher->offersFor($middle->fresh() ?? $middle));
        $this->assertCount(0, $dispatcher->offersFor($far->fresh() ?? $far));

        // Positions older than a few minutes do not count: keep them fresh while time passes.
        $this->travel(30)->seconds();
        $far->update(['located_at' => now()]);
        $outside->update(['located_at' => now()]);
        $this->assertCount(1, $dispatcher->offersFor($far->fresh() ?? $far));
        $this->assertCount(0, $dispatcher->offersFor($outside->fresh() ?? $outside), 'Beyond the search radius.');

        $this->assertSame($delivery->id, $dispatcher->offersFor($far->fresh() ?? $far)[0]['delivery']->id);
    }

    public function test_only_the_first_courier_to_accept_takes_the_run(): void
    {
        $first = $this->courierKmNorth(1, 'Primeiro');
        $second = $this->courierKmNorth(1.5, 'Segundo');
        $delivery = app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);

        $this->actingAs($first->user)->post(route('courier.runs.accept', $delivery))->assertSessionHasNoErrors();

        $this->actingAs($second->user)
            ->post(route('courier.runs.accept', $delivery))
            ->assertSessionHasErrors(['delivery' => 'Outro entregador já aceitou esta corrida.']);

        // One run at a time.
        $other = app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);
        $this->actingAs($first->user)
            ->post(route('courier.runs.accept', $other))
            ->assertSessionHasErrors(['delivery' => 'Termine a corrida atual antes de aceitar outra.']);
    }

    public function test_declined_and_given_back_runs_go_to_the_others(): void
    {
        $courier = $this->courierKmNorth(1);
        $other = $this->courierKmNorth(1.2, 'Outro');
        $delivery = app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);

        $this->actingAs($courier->user)->post(route('courier.runs.accept', $delivery));
        $this->actingAs($courier->user)->post(route('courier.runs.give-up', $delivery))->assertSessionHasNoErrors();

        $delivery->refresh();
        $this->assertSame(NetworkDeliveryStatus::Searching, $delivery->status);
        $this->assertNull($delivery->order->courier_id);
        $this->assertCount(0, app(Dispatcher::class)->offersFor($courier->fresh() ?? $courier), 'Not offered again to who gave it back.');
        $this->assertCount(1, app(Dispatcher::class)->offersFor($other->fresh() ?? $other));

        $this->actingAs($other->user)->post(route('courier.runs.decline', $delivery));
        $this->assertCount(0, app(Dispatcher::class)->offersFor($other->fresh() ?? $other));
    }

    public function test_the_store_can_call_it_off_until_the_pick_up(): void
    {
        $courier = $this->courierKmNorth(1);
        $order = $this->deliveryOrder();
        $delivery = app(Dispatcher::class)->call($order, 800, $this->owner);
        $this->actingAs($courier->user)->post(route('courier.runs.accept', $delivery));

        $this->actingAs($this->owner)->delete(route('orders.network.destroy', $order))->assertSessionHasNoErrors();

        $this->assertSame(NetworkDeliveryStatus::Cancelled, $delivery->fresh()?->status);
        $this->assertNull($order->fresh()?->courier_id);

        // After the pick-up the run is on: cancelling the order still charges it.
        $order = $this->deliveryOrder();
        $delivery = app(Dispatcher::class)->call($order, 800, $this->owner);
        $this->actingAs($courier->user)->post(route('courier.runs.accept', $delivery));
        $this->actingAs($courier->user)->post(route('courier.runs.pick-up', $delivery));

        $this->actingAs($this->owner)
            ->delete(route('orders.network.destroy', $order))
            ->assertSessionHasErrors(['delivery' => 'O entregador já retirou o pedido. Aguarde a entrega.']);

        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->assertSame(NetworkDeliveryStatus::Delivered, $delivery->fresh()?->status);
    }

    public function test_cancelling_the_order_cancels_a_run_still_searching(): void
    {
        $order = $this->deliveryOrder();
        $delivery = app(Dispatcher::class)->call($order, 800, $this->owner);

        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'cancelled'])->assertSessionHasNoErrors();

        $this->assertSame(NetworkDeliveryStatus::Cancelled, $delivery->fresh()?->status);
        $this->assertSame('Pedido cancelado', $delivery->fresh()?->cancel_reason);
    }

    public function test_when_the_store_cannot_call_the_network(): void
    {
        $dispatcher = app(Dispatcher::class);

        $this->assertSame('Aceite o pedido antes de chamar um entregador.', $dispatcher->blockedReason($this->deliveryOrder(OrderStatus::Pending)));

        $teamCourier = User::factory()->create();
        $this->store->users()->attach($teamCourier, ['role' => StoreRole::Courier->value]);
        $own = $this->deliveryOrder();
        $own->update(['courier_id' => $teamCourier->id]);
        $this->assertSame('O pedido já tem um entregador da equipe. Remova-o antes de chamar a rede.', $dispatcher->blockedReason($own));

        $order = $this->deliveryOrder();
        $this->actingAs($this->owner)
            ->post(route('orders.network.store', $order), ['fee_cents' => 300])
            ->assertSessionHasErrors(['fee' => 'O valor mínimo da corrida é R$ 5,00.']);

        DeliveryBill::query()->create([
            'store_id' => $this->store->id,
            'cycle' => 'daily',
            'period_start' => now()->subDays(4)->format('Y-m-d'),
            'period_end' => now()->subDays(4)->format('Y-m-d'),
            'deliveries_count' => 2,
            'amount_cents' => 1600,
            'due_at' => now()->subDays(3),
        ]);
        $this->assertSame('Há uma fatura de entregas vencida. Pague para voltar a chamar entregadores.', $dispatcher->blockedReason($order));

        $this->store->update(['latitude' => null, 'longitude' => null]);
        $this->assertSame('Marque a localização da loja no mapa, em "Rede de entregadores".', $dispatcher->blockedReason($order->fresh() ?? $order));
    }

    public function test_a_run_of_the_network_cannot_be_reassigned_to_the_team(): void
    {
        $teamCourier = User::factory()->create();
        $this->store->users()->attach($teamCourier, ['role' => StoreRole::Courier->value]);
        $order = $this->deliveryOrder();
        app(Dispatcher::class)->call($order, 800, $this->owner);

        $this->actingAs($this->owner)
            ->patch(route('orders.courier.update', $order), ['courier_id' => $teamCourier->id])
            ->assertSessionHasErrors('courier_id');
    }

    public function test_offline_or_unapproved_couriers_get_nothing(): void
    {
        app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);

        $offline = $this->courierKmNorth(1);
        $offline->update(['is_online' => false]);
        $stale = $this->courierKmNorth(1, 'Sem GPS');
        $stale->update(['located_at' => now()->subHours(3)]);

        $this->assertSame([], app(Dispatcher::class)->offersFor($offline->fresh() ?? $offline));
        $this->assertSame([], app(Dispatcher::class)->offersFor($stale->fresh() ?? $stale));
    }

    public function test_the_out_for_delivery_shortcut_offers_the_network(): void
    {
        $this->courierKmNorth(2);
        $this->courierKmNorth(30, 'Longe');
        $order = $this->deliveryOrder();

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('network.nearby', 1)
                ->where('network.feeCents', 800)
                ->where('orders.data.0.networkActive', false));

        app(Dispatcher::class)->call($order, 800, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders.data.0.networkActive', true));

        $this->store->update(['network_enabled' => false]);
        $this->actingAs($this->owner)
            ->get(route('orders.index'))
            ->assertInertia(fn (Assert $page) => $page->where('network', null));
    }

    public function test_with_nobody_nearby_the_run_goes_far_at_once(): void
    {
        $far = $this->courierKmNorth(7, 'Longe');
        $delivery = app(Dispatcher::class)->call($this->deliveryOrder(), 800, $this->owner);

        $this->assertSame(8.0, app(Dispatcher::class)->searchRadiusKm($delivery));
        $this->assertCount(1, app(Dispatcher::class)->offersFor($far->fresh() ?? $far));
    }

    public function test_sending_the_order_out_while_searching_cancels_the_run(): void
    {
        $order = $this->deliveryOrder();
        $delivery = app(Dispatcher::class)->call($order, 800, $this->owner);

        $this->actingAs($this->owner)->patch(route('orders.status.update', $order), ['status' => 'out_for_delivery'])->assertSessionHasNoErrors();

        $this->assertSame(NetworkDeliveryStatus::Cancelled, $delivery->fresh()?->status);
        $this->assertSame('Pedido saiu com outro entregador', $delivery->fresh()?->cancel_reason);
    }
}
