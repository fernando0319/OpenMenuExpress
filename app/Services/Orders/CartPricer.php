<?php

namespace App\Services\Orders;

use App\Enums\PricingMode;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\Store;
use App\Services\Catalog\PizzaMenu;
use App\Services\Pricing\ItemPriceCalculator;
use App\Services\Pricing\SelectedGroup;
use App\Services\Pricing\SelectedOption;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checks cart items against the catalog and prices them. Shared by every sales channel,
 * so the menu, the PDV and the waiter app always charge the same.
 *
 * @phpstan-type OptionInput array{option_id: int, quantity?: int|null}
 * @phpstan-type PizzaInput array{flavor_ids: list<int>, crust_id?: int|null}
 * @phpstan-type ItemInput array{product_id: int, quantity: int, notes?: string|null, options?: list<OptionInput>, pizza?: PizzaInput|null}
 */
final class CartPricer
{
    public function __construct(private readonly ItemPriceCalculator $calculator) {}

    /**
     * @param  list<ItemInput>  $items
     * @return list<PricedLine>
     */
    public function price(Store $store, array $items): array
    {
        $productIds = array_values(array_unique(array_map(fn (array $item) => $item['product_id'], $items)));

        $products = $store->products()
            ->whereIn('id', $productIds)
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->get()
            ->keyBy('id');

        $links = ProductOptionGroup::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('product_id');

        $groups = OptionGroup::query()
            ->where('store_id', $store->id)
            ->where('is_active', true)
            ->whereIn('id', $links->flatten()->pluck('option_group_id'))
            ->with(['options' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->keyBy('id');

        $lines = [];
        $pizzaMenu = null;

        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);

            if (! $product instanceof Product) {
                $this->fail("items.{$index}.product_id", 'Um dos produtos não está mais disponível. Remova-o do carrinho.');
            }

            if ($product->track_stock && ($product->stock_quantity ?? 0) < $item['quantity']) {
                $this->fail("items.{$index}.quantity", "Só restam {$product->stock_quantity} unidade(s) de {$product->name}.");
            }

            /** @var Collection<int, ProductOptionGroup> $productLinks */
            $productLinks = $links->get($product->id, collect());

            [$selectedGroups, $chosenOptions] = $this->resolveOptions($index, $product, $productLinks, $groups, $item['options'] ?? []);
            $baseCents = $product->effectivePriceCents();

            // A pizza size: the flavors set the price, the crust is added on top.
            if ($product->isPizza()) {
                $pizza = ($pizzaMenu ??= PizzaMenu::for($store))->price(
                    $product,
                    $item['pizza']['flavor_ids'] ?? [],
                    $item['pizza']['crust_id'] ?? null,
                    "items.{$index}.pizza",
                );
                $baseCents = $pizza['flavorsCents'];
                $chosenOptions = [...$pizza['choices'], ...$chosenOptions];

                if ($pizza['crustCents'] > 0) {
                    $selectedGroups[] = new SelectedGroup(PricingMode::Sum, [new SelectedOption($pizza['crustCents'], 1)]);
                }
            }

            $lines[] = new PricedLine(
                $product,
                $this->calculator->calculate($baseCents, $item['quantity'], $selectedGroups),
                $item['notes'] ?? null,
                $chosenOptions,
            );
        }

        return $lines;
    }

    /**
     * @param  list<PricedLine>  $lines
     */
    public static function subtotal(array $lines): int
    {
        return array_sum(array_map(fn (PricedLine $line) => $line->price->totalCents(), $lines));
    }

    /**
     * Locks the rows and decrements stock, so two simultaneous orders cannot sell the same unit.
     * Call it inside the order transaction.
     *
     * @param  list<PricedLine>  $lines
     */
    public function reserveStock(array $lines): void
    {
        $wanted = [];

        foreach ($lines as $line) {
            if ($line->product->track_stock) {
                $wanted[$line->product->id] = ($wanted[$line->product->id] ?? 0) + $line->price->quantity;
            }
        }

        foreach ($wanted as $productId => $quantity) {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

            if (($product->stock_quantity ?? 0) < $quantity) {
                $this->fail('items', "Só restam {$product->stock_quantity} unidade(s) de {$product->name}.");
            }

            $product->decrement('stock_quantity', $quantity);
        }
    }

    /**
     * Checks the chosen options against each group's rules for this product.
     *
     * @param  Collection<int, ProductOptionGroup>  $productLinks
     * @param  Collection<int, OptionGroup>  $groups
     * @param  list<OptionInput>  $selections
     * @return array{0: list<SelectedGroup>, 1: list<array{option_id: int, group_name: string, option_name: string, quantity: int, price_cents: int}>}
     */
    private function resolveOptions(int $index, Product $product, Collection $productLinks, Collection $groups, array $selections): array
    {
        $quantities = [];

        foreach ($selections as $selection) {
            $quantities[$selection['option_id']] = ($quantities[$selection['option_id']] ?? 0) + max(1, (int) ($selection['quantity'] ?? 1));
        }

        $selectedGroups = [];
        $chosenOptions = [];
        $matched = [];

        foreach ($productLinks as $link) {
            $group = $groups->get($link->option_group_id);

            if (! $group instanceof OptionGroup) {
                continue;
            }

            $selected = [];
            $count = 0;

            foreach ($group->options as $option) {
                $quantity = $quantities[$option->id] ?? 0;

                if ($quantity === 0) {
                    continue;
                }

                if ($quantity > 1 && ! $group->allows_quantity) {
                    $this->fail("items.{$index}.options", "Em {$group->name}, cada opção só pode ser escolhida uma vez.");
                }

                $matched[$option->id] = true;
                $count += $quantity;
                $selected[] = new SelectedOption($option->price_cents, $quantity);
                $chosenOptions[] = $this->chosenOption($group, $option, $quantity);
            }

            $min = $link->min_choices ?? $group->min_choices;
            $max = $link->max_choices ?? $group->max_choices;

            if ($count < $min) {
                $this->fail("items.{$index}.options", "Escolha pelo menos {$min} em {$group->name} ({$product->name}).");
            }

            if ($count > $max) {
                $this->fail("items.{$index}.options", "Escolha no máximo {$max} em {$group->name} ({$product->name}).");
            }

            if ($selected !== []) {
                $selectedGroups[] = new SelectedGroup($group->pricing_mode, $selected);
            }
        }

        if (array_diff_key($quantities, $matched) !== []) {
            $this->fail("items.{$index}.options", "Uma das opções escolhidas para {$product->name} não está mais disponível.");
        }

        return [$selectedGroups, $chosenOptions];
    }

    /**
     * @return array{option_id: int, group_name: string, option_name: string, quantity: int, price_cents: int}
     */
    private function chosenOption(OptionGroup $group, Option $option, int $quantity): array
    {
        return [
            'option_id' => $option->id,
            'group_name' => $group->name,
            'option_name' => $option->name,
            'quantity' => $quantity,
            'price_cents' => $option->price_cents,
        ];
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
