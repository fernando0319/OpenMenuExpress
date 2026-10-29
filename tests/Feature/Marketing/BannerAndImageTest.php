<?php

namespace Tests\Feature\Marketing;

use App\Enums\BannerLinkType;
use App\Enums\StoreRole;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BannerAndImageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->store = Store::factory()->create(['slug' => 'pizzaria-ze']);
        $this->owner = User::factory()->create();
        $this->store->users()->attach($this->owner, ['role' => StoreRole::Owner->value]);
    }

    public function test_banner_is_uploaded_and_shown_in_the_menu(): void
    {
        $product = Product::factory()->for(Category::factory()->for($this->store))->create();

        $this->actingAs($this->owner)
            ->post(route('marketing.banners.store'), [
                'title' => 'Pizza do dia',
                'image' => UploadedFile::fake()->image('banner.jpg', 1200, 500),
                'link_type' => 'product',
                'link_target' => $product->id,
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $banner = $this->store->banners()->sole();
        Storage::disk('public')->assertExists($banner->image_path);
        $this->assertStringStartsWith("stores/{$this->store->id}/banners/", $banner->image_path);

        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page
            ->has('banners', 1)
            ->where('banners.0.linkType', 'product')
            ->where('banners.0.linkTarget', (string) $product->id));
    }

    public function test_banners_outside_their_period_are_hidden(): void
    {
        $this->banner(['title' => 'Antigo', 'ends_at' => now()->subHour()]);
        $this->banner(['title' => 'Futuro', 'starts_at' => now()->addDay()]);
        $this->banner(['title' => 'Desligado', 'is_active' => false]);
        $this->banner(['title' => 'Agora']);

        $this->get('/pizzaria-ze')->assertInertia(fn (Assert $page) => $page
            ->has('banners', 1)
            ->where('banners.0.title', 'Agora'));
    }

    public function test_link_target_must_belong_to_the_store(): void
    {
        $foreignProduct = Product::factory()->for(Category::factory()->for(Store::factory()))->create();

        $this->actingAs($this->owner)
            ->post(route('marketing.banners.store'), [
                'title' => 'Invasor',
                'image' => UploadedFile::fake()->image('b.jpg'),
                'link_type' => 'product',
                'link_target' => $foreignProduct->id,
            ])
            ->assertSessionHasErrors('link_target');

        $this->actingAs($this->owner)
            ->post(route('marketing.banners.store'), [
                'title' => 'Arquivo errado',
                'image' => UploadedFile::fake()->create('virus.exe', 10),
                'link_type' => 'none',
            ])
            ->assertSessionHasErrors('image');
    }

    public function test_banners_are_reordered_and_deleted_with_their_image(): void
    {
        $first = $this->banner(['title' => 'Primeiro', 'sort_order' => 0]);
        $second = $this->banner(['title' => 'Segundo', 'sort_order' => 1]);

        $this->actingAs($this->owner)->patch(route('marketing.banners.move', $second), ['direction' => 'up']);
        $this->assertSame(['Segundo', 'Primeiro'], $this->store->banners()->orderBy('sort_order')->pluck('title')->all());

        $this->actingAs($this->owner)->delete(route('marketing.banners.destroy', $first));
        $this->assertModelMissing($first);
        Storage::disk('public')->assertMissing($first->image_path);
    }

    public function test_product_photo_is_uploaded_replaced_and_removed(): void
    {
        $category = Category::factory()->for($this->store)->create();
        $data = ['category_id' => $category->id, 'name' => 'Pizza', 'price' => '40,00'];

        $this->actingAs($this->owner)
            ->post(route('catalog.products.store'), [...$data, 'image' => UploadedFile::fake()->image('pizza.jpg')])
            ->assertSessionHasNoErrors();

        $product = $this->store->products()->sole();
        $firstPath = (string) $product->image_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAs($this->owner)
            ->put(route('catalog.products.update', $product), [...$data, 'image' => UploadedFile::fake()->image('nova.png')])
            ->assertSessionHasNoErrors();

        Storage::disk('public')->assertMissing($firstPath);
        $this->assertNotSame($firstPath, $product->fresh()?->image_path);

        $this->actingAs($this->owner)
            ->put(route('catalog.products.update', $product), [...$data, 'remove_image' => true])
            ->assertSessionHasNoErrors();

        $this->assertNull($product->fresh()?->image_path);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function banner(array $attributes): Banner
    {
        $path = UploadedFile::fake()->image('b.jpg')->store("stores/{$this->store->id}/banners", 'public');

        return $this->store->banners()->create([
            'image_path' => $path,
            'link_type' => BannerLinkType::None,
            ...$attributes,
        ]);
    }
}
