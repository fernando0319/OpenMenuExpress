<?php

namespace Tests\Feature\CourierNetwork;

use App\Enums\CourierStatus;
use App\Models\NetworkCourier;
use App\Models\User;
use App\Support\NetworkSettings;
use Database\Factories\NetworkCourierFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourierRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        NetworkSettings::save(['enabled' => true]);
    }

    public function test_someone_signs_up_and_waits_for_approval(): void
    {
        $cpf = NetworkCourierFactory::cpf();

        $this->post(route('courier.register.store'), $this->form(['cpf' => substr($cpf, 0, 3).'.'.substr($cpf, 3, 3).'.'.substr($cpf, 6, 3).'-'.substr($cpf, 9)]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('courier.home'));

        $courier = NetworkCourier::query()->sole();
        $this->assertSame($cpf, $courier->cpf);
        $this->assertSame('5542991112222', $courier->phone);
        $this->assertSame('ABC1D23', $courier->vehicle_plate);
        $this->assertSame('+5542991112222', $courier->pix_key);
        $this->assertSame(CourierStatus::Pending, $courier->status);
        Storage::disk('local')->assertExists((string) $courier->document_path);
        $this->assertAuthenticatedAs($courier->user);

        $this->get(route('courier.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('courier/Home')
                ->where('courier.status', 'pending')
                ->where('offers', []));

        $this->post(route('courier.online'), ['online' => true])->assertSessionHasErrors('courier');
    }

    public function test_the_form_checks_the_documents(): void
    {
        $this->post(route('courier.register.store'), $this->form(['cpf' => '111.111.111-11', 'vehicle_plate' => '']))
            ->assertSessionHasErrors(['cpf', 'vehicle_plate']);

        // A bicycle needs no plate.
        $this->post(route('courier.register.store'), $this->form(['vehicle' => 'bicycle', 'vehicle_plate' => '']))
            ->assertSessionHasNoErrors();
        $this->assertNull(NetworkCourier::query()->sole()->vehicle_plate);
    }

    public function test_the_platform_owner_reviews_the_registration(): void
    {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->post(route('courier.register.store'), $this->form());
        $courier = NetworkCourier::query()->sole();
        auth()->logout();

        $courier->user->markEmailAsVerified();
        $this->actingAs($courier->user)->get(route('admin.network.couriers.document', $courier))->assertForbidden();

        $this->actingAs($admin)->get(route('admin.network.couriers.index'))
            ->assertInertia(fn (Assert $page) => $page->where('couriers.data.0.name', 'João Entregador')->where('pendingCount', 1));
        $this->actingAs($admin)->get(route('admin.network.couriers.show', $courier))->assertOk();
        $this->actingAs($admin)->get(route('admin.network.couriers.document', $courier))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAs($admin)->post(route('admin.network.couriers.review', $courier), ['status' => 'rejected'])->assertSessionHasErrors('reason');
        $this->actingAs($admin)->post(route('admin.network.couriers.review', $courier), ['status' => 'approved'])->assertSessionHasNoErrors();

        $this->actingAs($courier->user)->post(route('courier.online'), ['online' => true])->assertSessionHasNoErrors();
        $this->assertTrue($courier->fresh()?->is_online);

        $this->actingAs($admin)->post(route('admin.network.couriers.review', $courier), ['status' => 'blocked', 'reason' => 'Reclamações das lojas'])->assertSessionHasNoErrors();
        $this->assertFalse($courier->fresh()?->is_online);
    }

    public function test_couriers_land_on_their_app_after_signing_in(): void
    {
        $courier = NetworkCourier::factory()->create();
        $courier->user->update(['email' => 'joao@example.com']);

        $this->post(route('login.store'), ['email' => 'joao@example.com', 'password' => 'password'])
            ->assertRedirect(route('courier.home'));

        // Store people without a registration are sent to sign up.
        auth()->logout();
        $this->actingAs(User::factory()->create())->get(route('courier.home'))->assertRedirect(route('courier.register'));
    }

    public function test_couriers_keep_their_details_up_to_date(): void
    {
        $courier = NetworkCourier::factory()->create();

        $this->actingAs($courier->user)->get(route('courier.profile'))->assertOk();

        $this->actingAs($courier->user)->put(route('courier.profile.update'), [
            'phone' => '(42) 99111-3333',
            'city' => 'Ponta Grossa',
            'state' => 'pr',
            'vehicle' => 'car',
            'vehicle_plate' => 'xyz-9876',
            'pix_key_type' => 'email',
            'pix_key' => 'Joao@Example.com',
        ])->assertSessionHasNoErrors();

        $courier->refresh();
        $this->assertSame('PR', $courier->state);
        $this->assertSame('XYZ9876', $courier->vehicle_plate);
        $this->assertSame('joao@example.com', $courier->pix_key);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'name' => 'João Entregador',
            'email' => 'joao.entregador@example.com',
            'password' => 'Senha-forte-123',
            'password_confirmation' => 'Senha-forte-123',
            'cpf' => NetworkCourierFactory::cpf(),
            'phone' => '(42) 99111-2222',
            'city' => 'Ponta Grossa',
            'state' => 'PR',
            'vehicle' => 'motorcycle',
            'vehicle_plate' => 'abc-1d23',
            'pix_key_type' => 'phone',
            'pix_key' => '42 99111-2222',
            'document' => UploadedFile::fake()->image('cnh.jpg'),
            'terms' => true,
        ], $overrides);
    }
}
