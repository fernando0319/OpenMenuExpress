<?php

namespace App\Services\Catalog;

use App\Enums\FlavorKind;
use App\Models\PizzaCrust;
use App\Models\PizzaFlavor;
use App\Models\PizzaFlavorGroup;
use App\Models\Product;
use App\Models\Store;
use App\Support\ImageStorage;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A store's pizza tables (flavor groups with a price per size, flavors, crusts), loaded once:
 * what the builder shows for each size and the price the server charges.
 *
 * @phpstan-type PizzaChoice array{option_id: null, group_name: string, option_name: string, quantity: int, price_cents: int}
 */
final class PizzaMenu
{
    public const FLAVORS_GROUP = 'Sabores';

    public const CRUST_GROUP = 'Borda';

    /**
     * @param  Collection<int, PizzaFlavorGroup>  $groups  active, with their active flavors
     * @param  Collection<int, PizzaCrust>  $crusts  active
     */
    private function __construct(
        private readonly Store $store,
        private readonly Collection $groups,
        private readonly Collection $crusts,
    ) {}

    public static function for(Store $store): self
    {
        return new self(
            $store,
            $store->pizzaFlavorGroups()->where('is_active', true)
                ->with(['flavors' => fn ($query) => $query->where('is_active', true)])
                ->get(),
            $store->pizzaCrusts()->where('is_active', true)->get(),
        );
    }

    /**
     * "½ ", "⅓ ", "¼ " in front of each flavor of a pizza with that many flavors.
     */
    public static function fraction(int $count): string
    {
        return match ($count) {
            1 => '',
            2 => '½ ',
            3 => '⅓ ',
            4 => '¼ ',
            default => "1/{$count} ",
        };
    }

    /**
     * What the builder of this size offers.
     *
     * @return array<string, mixed>
     */
    public function forProduct(Product $product): array
    {
        $groups = [];

        foreach ($this->groups as $group) {
            $price = $group->priceFor($product->id);

            if ($price === null) {
                continue;
            }

            $flavors = $group->flavors
                ->filter(fn (PizzaFlavor $flavor) => $flavor->isOfferedIn($product->id))
                ->map(fn (PizzaFlavor $flavor) => [
                    'id' => $flavor->id,
                    'name' => $flavor->name,
                    'description' => $flavor->description,
                    'imageUrl' => ImageStorage::url($flavor->image_path),
                    'priceCents' => $price + $flavor->extra_cents,
                ])
                ->values();

            if ($flavors->isNotEmpty()) {
                $groups[] = ['id' => $group->id, 'name' => $group->name, 'kind' => $group->kind->value, 'priceCents' => $price, 'flavors' => $flavors];
            }
        }

        $prices = collect($groups)->flatMap(fn (array $group) => $group['flavors']->pluck('priceCents'));

        return [
            'slices' => $product->pizza_slices,
            'diameterCm' => $product->pizza_diameter_cm,
            'maxFlavors' => max(1, (int) $product->pizza_max_flavors),
            'fromCents' => $prices->isEmpty() ? 0 : (int) $prices->min(),
            'pricing' => $this->store->pizza_pricing->value,
            'mixKinds' => $this->store->pizza_mix_kinds,
            'groups' => $groups,
            'crusts' => $this->crusts
                ->filter(fn (PizzaCrust $crust) => $crust->priceFor($product->id) !== null)
                ->map(fn (PizzaCrust $crust) => [
                    'id' => $crust->id,
                    'name' => $crust->name,
                    'description' => $crust->description,
                    'priceCents' => (int) $crust->priceFor($product->id),
                ])
                ->values(),
        ];
    }

    /**
     * Checks the chosen flavors and crust of one pizza and prices them.
     *
     * @param  list<int>  $flavorIds
     * @return array{flavorsCents: int, crustCents: int, choices: list<PizzaChoice>}
     */
    public function price(Product $product, array $flavorIds, ?int $crustId, string $errorKey): array
    {
        $max = max(1, (int) $product->pizza_max_flavors);

        if ($flavorIds === []) {
            $this->fail($errorKey, "Escolha pelo menos 1 sabor para a {$product->name}.");
        }

        if (count($flavorIds) > $max) {
            $this->fail($errorKey, "A {$product->name} aceita até {$max} sabor(es).");
        }

        if (count(array_unique($flavorIds)) !== count($flavorIds)) {
            $this->fail($errorKey, 'Escolha sabores diferentes em cada parte da pizza.');
        }

        $fraction = self::fraction(count($flavorIds));
        $prices = [];
        $kinds = [];
        $choices = [];

        foreach ($flavorIds as $flavorId) {
            [$group, $flavor] = $this->findFlavor($flavorId);
            $groupPrice = $group?->priceFor($product->id);

            if ($group === null || $flavor === null || $groupPrice === null || ! $flavor->isOfferedIn($product->id)) {
                $this->fail($errorKey, "Um dos sabores escolhidos não está disponível na {$product->name}.");
            }

            $prices[] = $groupPrice + $flavor->extra_cents;
            $kinds[$group->kind->value] = true;
            $choices[] = ['option_id' => null, 'group_name' => self::FLAVORS_GROUP, 'option_name' => $fraction.$flavor->name, 'quantity' => 1, 'price_cents' => 0];
        }

        if (! $this->store->pizza_mix_kinds && isset($kinds[FlavorKind::Sweet->value], $kinds[FlavorKind::Savory->value])) {
            $this->fail($errorKey, 'Esta pizzaria não mistura sabores doces e salgados na mesma pizza.');
        }

        $crustCents = 0;

        if ($crustId !== null) {
            $crust = $this->crusts->firstWhere('id', $crustId);
            $price = $crust?->priceFor($product->id);

            if (! $crust instanceof PizzaCrust || $price === null) {
                $this->fail($errorKey, "Esta borda não está disponível na {$product->name}.");
            }

            $crustCents = $price;
            $choices[] = ['option_id' => null, 'group_name' => self::CRUST_GROUP, 'option_name' => $crust->name, 'quantity' => 1, 'price_cents' => $price];
        }

        return [
            'flavorsCents' => $this->store->pizza_pricing->price($prices),
            'crustCents' => $crustCents,
            'choices' => $choices,
        ];
    }

    /**
     * @return array{0: PizzaFlavorGroup|null, 1: PizzaFlavor|null}
     */
    private function findFlavor(int $flavorId): array
    {
        foreach ($this->groups as $group) {
            $flavor = $group->flavors->firstWhere('id', $flavorId);

            if ($flavor instanceof PizzaFlavor) {
                return [$group, $flavor];
            }
        }

        return [null, null];
    }

    /**
     * @return never
     */
    private function fail(string $key, string $message): void
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
