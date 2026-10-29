<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Support\ImageStorage;

/**
 * The sellable catalog as the menu and the PDV show it: active categories with their
 * active products and option groups, in menu order.
 */
final class MenuCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function for(Store $store): array
    {
        $categories = $store->categories()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->with(['products' => fn ($query) => $query
                ->where('is_active', true)
                ->orderByDesc('is_featured')
                ->orderBy('sort_order')
                ->orderBy('name'),
            ])
            ->get();

        $productIds = $categories->flatMap(fn (Category $category) => $category->products->pluck('id'));

        $links = ProductOptionGroup::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        $groups = OptionGroup::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->with(['options' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->keyBy('id');

        $pizzaMenu = $categories->contains(fn (Category $category) => $category->products->contains(fn (Product $product) => $product->isPizza()))
            ? PizzaMenu::for($store)
            : null;

        // A pizza size shows "a partir de" its cheapest flavor and carries its builder data.
        $pizzas = [];

        foreach ($categories as $category) {
            foreach ($category->products as $product) {
                if ($pizzaMenu !== null && $product->isPizza()) {
                    $pizzas[$product->id] = $pizzaMenu->forProduct($product);
                }
            }
        }

        return array_values($categories
            ->filter(fn (Category $category) => $category->products->isNotEmpty())
            ->map(fn (Category $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'products' => $category->products->map(fn (Product $product) => [
                    'id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'imageUrl' => ImageStorage::url($product->image_path),
                    'priceCents' => isset($pizzas[$product->id]) ? $pizzas[$product->id]['fromCents'] : $product->price_cents,
                    'effectivePriceCents' => isset($pizzas[$product->id]) ? $pizzas[$product->id]['fromCents'] : $product->effectivePriceCents(),
                    'pizza' => $pizzas[$product->id] ?? null,
                    'isFeatured' => $product->is_featured,
                    'soldOut' => $product->track_stock && ($product->stock_quantity ?? 0) <= 0,
                    'optionGroups' => $links->get($product->id, collect())
                        ->map(function (ProductOptionGroup $link) use ($groups): ?array {
                            $group = $groups->get($link->option_group_id);

                            if (! $group instanceof OptionGroup || $group->options->isEmpty()) {
                                return null;
                            }

                            return [
                                'id' => $group->id,
                                'name' => $group->name,
                                'minChoices' => $link->min_choices ?? $group->min_choices,
                                'maxChoices' => $link->max_choices ?? $group->max_choices,
                                'pricingMode' => $group->pricing_mode->value,
                                'allowsQuantity' => $group->allows_quantity,
                                'options' => $group->options->map(fn (Option $option) => [
                                    'id' => $option->id,
                                    'name' => $option->name,
                                    'description' => $option->description,
                                    'priceCents' => $option->price_cents,
                                ])->values(),
                            ];
                        })
                        ->filter()
                        ->values(),
                ])->values(),
            ])
            ->all());
    }
}
