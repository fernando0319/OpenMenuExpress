<?php

namespace Tests\Feature\Scheduling;

use App\Enums\Fulfillment;
use App\Enums\OrderChannel;
use App\Enums\OrderStatus;
use App\Enums\StoreRole;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Services\Scheduling\StoreSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Dates: 2026-10-19 is a Monday. The store is in America/Sao_Paulo (UTC−3).
 */
class StoreScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create([
            'slug' => 'pizzaria-ze',
            'timezone' => 'America/Sao_Paulo',
            'scheduling_lead_minutes' => 60,
            'scheduling_slot_minutes' => 30,
            'scheduling_days_ahead' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_store_without_hours_is_always_open_unless_paused(): void
    {
        $this->assertTrue($this->schedule()->isOpenAt($this->local('2026-10-19 04:00')));

        $this->store->update(['is_paused' => true]);
        $this->assertFalse($this->schedule()->isOpenAt($this->local('2026-10-19 12:00')));
    }

    public function test_open_only_inside_the_shift_of_the_day(): void
    {
        $this->shift(1, '18:00', '23:00');

        $this->assertTrue($this->schedule()->isOpenAt($this->local('2026-10-19 19:00')));
        $this->assertFalse($this->schedule()->isOpenAt($this->local('2026-10-19 23:30')));
        $this->assertFalse($this->schedule()->isOpenAt($this->local('2026-10-20 19:00')));
    }

    public function test_shift_past_midnight_belongs_to_the_day_it_starts(): void
    {
        $this->shift(5, '18:00', '02:00'); // sexta

        $this->assertTrue($this->schedule()->isOpenAt($this->local('2026-10-23 22:00')));
        $this->assertTrue($this->schedule()->isOpenAt($this->local('2026-10-24 01:30')));
        $this->assertFalse($this->schedule()->isOpenAt($this->local('2026-10-24 02:30')));
        $this->assertFalse($this->schedule()->isOpenAt($this->local('2026-10-24 19:00')));
    }

    public function test_next_opening_is_the_next_shift_start(): void
    {
        $this->shift(1, '11:00', '14:00');
        $this->shift(1, '18:00', '23:00');

        $next = $this->schedule()->nextOpening($this->local('2026-10-19 15:00'));

        $this->assertEquals($this->local('2026-10-19 18:00'), $next);
    }

    public function test_slots_respect_lead_time_and_capacity(): void
    {
        $this->shift(1, '18:00', '20:00');
        $this->store->update(['accepts_scheduling' => true]);
        $now = $this->local('2026-10-19 17:30');

        $times = array_map(
            fn (CarbonImmutable $slot) => $slot->setTimezone('America/Sao_Paulo')->format('H:i'),
            $this->schedule()->availableSlots($now),
        );
        $this->assertSame(['18:30', '19:00', '19:30'], $times);

        $this->store->update(['scheduling_slot_capacity' => 1]);
        $this->bookedOrder($this->local('2026-10-19 19:00'));

        $times = array_map(
            fn (CarbonImmutable $slot) => $slot->setTimezone('America/Sao_Paulo')->format('H:i'),
            $this->schedule()->availableSlots($now),
        );
        $this->assertSame(['18:30', '19:30'], $times);
    }

    public function test_closed_store_without_scheduling_rejects_orders(): void
    {
        $this->shift(1, '18:00', '23:00');
        CarbonImmutable::setTestNow($this->local('2026-10-19 10:00'));

        $this->order()->assertSessionHasErrors('store');
    }

    public function test_closed_store_with_scheduling_accepts_a_valid_slot_only(): void
    {
        $this->shift(1, '18:00', '23:00');
        $this->store->update(['accepts_scheduling' => true]);
        CarbonImmutable::setTestNow($this->local('2026-10-19 10:00'));

        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page
            ->where('store.isOpen', false)
            ->where('store.nextOpening', 'Hoje às 18:00')
            ->where('slots.0.time', '18:00')
            ->where('slots.0.day', 'Hoje'));

        $this->order(['scheduled_for' => $this->local('2026-10-19 18:15')->toIso8601ZuluString()])
            ->assertSessionHasErrors('scheduled_for');

        $this->order(['scheduled_for' => $this->local('2026-10-19 19:30')->toIso8601ZuluString()])
            ->assertSessionHasNoErrors();

        $this->assertEquals($this->local('2026-10-19 19:30'), Order::query()->sole()->scheduled_for);
    }

    public function test_owner_saves_hours_and_scheduling_rules(): void
    {
        $owner = User::factory()->create();
        $this->store->users()->attach($owner, ['role' => StoreRole::Owner->value]);
        $this->shift(0, '10:00', '12:00');

        $this->actingAs($owner)
            ->put(route('store-settings.hours.update'), [
                'shifts' => [
                    ['weekday' => 1, 'opens' => '11:00', 'closes' => '14:00'],
                    ['weekday' => 1, 'opens' => '18:00', 'closes' => '23:30'],
                ],
                'accepts_scheduling' => true,
                'scheduling_lead_minutes' => 45,
                'scheduling_days_ahead' => 3,
                'scheduling_slot_minutes' => 15,
                'scheduling_slot_capacity' => 5,
            ])
            ->assertSessionHasNoErrors();

        $week = $this->schedule()->weekly();
        $this->assertSame([], $week[0]['shifts']);
        $this->assertSame([['opens' => '11:00', 'closes' => '14:00'], ['opens' => '18:00', 'closes' => '23:30']], $week[1]['shifts']);
        $this->assertSame(45, $this->store->fresh()?->scheduling_lead_minutes);

        $this->actingAs($owner)
            ->put(route('store-settings.hours.update'), [
                'shifts' => [['weekday' => 1, 'opens' => '25:00', 'closes' => '23:30']],
                'scheduling_lead_minutes' => 45,
                'scheduling_days_ahead' => 3,
                'scheduling_slot_minutes' => 15,
            ])
            ->assertSessionHasErrors('shifts.0.opens');
    }

    private function schedule(): StoreSchedule
    {
        return StoreSchedule::for($this->store->fresh() ?? $this->store);
    }

    private function shift(int $weekday, string $opens, string $closes): void
    {
        $this->store->openingHours()->create(['weekday' => $weekday, 'opens_at' => $opens, 'closes_at' => $closes]);
    }

    private function local(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo')->utc();
    }

    private function bookedOrder(CarbonImmutable $slot): void
    {
        $this->store->orders()->create([
            'number' => 99,
            'customer_name' => 'Ana',
            'channel' => OrderChannel::Online,
            'fulfillment' => Fulfillment::Pickup,
            'status' => OrderStatus::Pending,
            'subtotal_cents' => 1000,
            'total_cents' => 1000,
            'payment_method' => 'pix',
            'scheduled_for' => $slot,
            'placed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return TestResponse<Response>
     */
    private function order(array $overrides = []): TestResponse
    {
        $product = Product::factory()->for(Category::factory()->for($this->store))->create();

        return $this->post('/pizzaria-ze/orders', array_merge([
            'fulfillment' => 'pickup',
            'customer' => ['name' => 'Maria', 'phone' => '11999998888'],
            'payment_method' => 'pix',
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ], $overrides));
    }
}
