<?php

namespace Tests\Feature\Panel;

use App\Enums\StoreRole;
use App\Models\Store;
use App\Models\User;
use App\Support\StoreBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze', 'name' => 'Pizzaria do Zé']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_a_store_without_branding_uses_the_defaults(): void
    {
        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page
            ->where('branding.logoUrl', null)
            ->where('branding.coverUrl', null)
            ->where('branding.primaryColor', StoreBranding::DEFAULT_PRIMARY)
            ->where('branding.accentColor', StoreBranding::DEFAULT_ACCENT)
            ->where('branding.theme', 'default'));
    }

    public function test_owner_sets_logo_cover_colors_and_texts(): void
    {
        $this->actingAs($this->owner)
            ->post(route('store-settings.appearance.update'), [
                '_method' => 'put',
                'name' => ' Pizzaria do Zé Delivery ',
                'description' => 'A melhor da cidade.',
                'primary_color' => '#B91C1C',
                'accent_color' => '#f97316',
                'logo' => UploadedFile::fake()->image('logo.png', 512, 512),
                'cover' => UploadedFile::fake()->image('capa.jpg', 1500, 500),
            ])
            ->assertSessionHasNoErrors();

        $store = $this->store->fresh();
        $this->assertNotNull($store);
        $this->assertSame('Pizzaria do Zé Delivery', $store->name);
        $this->assertSame('#b91c1c', $store->getAttribute('primary_color'));
        Storage::disk('public')->assertExists((string) $store->getAttribute('logo_path'));
        Storage::disk('public')->assertExists((string) $store->getAttribute('cover_path'));

        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page
            ->where('store.description', 'A melhor da cidade.')
            ->where('branding.primaryColor', '#b91c1c')
            ->where('branding.accentColor', '#f97316')
            ->where('branding.logoUrl', fn ($url) => is_string($url) && str_contains($url, '/branding/')));

        // Replacing the logo deletes the old file; removing the cover deletes it too.
        $oldLogo = (string) $store->getAttribute('logo_path');
        $oldCover = (string) $store->getAttribute('cover_path');

        $this->actingAs($this->owner)
            ->post(route('store-settings.appearance.update'), [
                '_method' => 'put',
                'name' => 'Pizzaria do Zé Delivery',
                'primary_color' => '#b91c1c',
                'accent_color' => '#f97316',
                'logo' => UploadedFile::fake()->image('novo.png', 512, 512),
                'remove_cover' => true,
            ])
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($oldLogo);
        Storage::disk('public')->assertMissing($oldCover);
        $this->assertNull($this->store->fresh()?->getAttribute('cover_path'));
    }

    public function test_invalid_colors_and_files_are_refused(): void
    {
        $this->actingAs($this->owner)
            ->post(route('store-settings.appearance.update'), [
                '_method' => 'put',
                'name' => 'Pizzaria do Zé',
                'primary_color' => 'red',
                'accent_color' => '#12345',
                'logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors(['primary_color', 'accent_color', 'logo']);
    }

    public function test_contrast_check_flags_light_primary_colors(): void
    {
        $this->assertFalse(StoreBranding::isTooLight(StoreBranding::DEFAULT_PRIMARY));
        $this->assertFalse(StoreBranding::isTooLight('#1f2937'));
        $this->assertTrue(StoreBranding::isTooLight('#fde047'));
        $this->assertTrue(StoreBranding::isTooLight('#ffffff'));
    }

    public function test_only_owners_and_managers_change_the_look(): void
    {
        $attendant = User::factory()->create();
        $this->store->users()->attach($attendant, ['role' => StoreRole::Attendant->value]);

        $this->actingAs($attendant)->get(route('store-settings.appearance.edit'))->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('store-settings.appearance.edit'))
            ->assertInertia(fn (Assert $page) => $page->component('store-settings/Appearance')->has('presets', 6));
    }
}
