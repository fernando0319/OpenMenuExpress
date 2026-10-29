<?php

namespace App\Http\Controllers\Catalog;

use App\Enums\PricingMode;
use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\OptionGroupRequest;
use App\Models\Option;
use App\Models\OptionGroup;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OptionGroupController extends Controller
{
    use InteractsWithCurrentStore;

    public function index(): Response
    {
        $groups = $this->currentStore()->optionGroups()
            ->withCount(['options', 'products'])
            ->orderBy('name')
            ->get()
            ->map(fn (OptionGroup $group) => [
                'id' => $group->id,
                'name' => $group->name,
                'min_choices' => $group->min_choices,
                'max_choices' => $group->max_choices,
                'pricing_mode_label' => $group->pricing_mode->label(),
                'is_active' => $group->is_active,
                'options_count' => $group->getAttribute('options_count'),
                'products_count' => $group->getAttribute('products_count'),
            ]);

        return Inertia::render('catalog/option-groups/Index', [
            'groups' => $groups,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('catalog/option-groups/Form', [
            'group' => null,
            'pricingModes' => $this->pricingModes(),
        ]);
    }

    public function store(OptionGroupRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $group = $this->currentStore()->optionGroups()->create($request->safe()->except('options'));
            $this->syncOptions($group, $request->validated('options'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo de opções criado.']);

        return to_route('catalog.option-groups.index');
    }

    public function edit(OptionGroup $optionGroup): Response
    {
        $this->ensureBelongsToCurrentStore($optionGroup);

        return Inertia::render('catalog/option-groups/Form', [
            'group' => [
                'id' => $optionGroup->id,
                'name' => $optionGroup->name,
                'min_choices' => $optionGroup->min_choices,
                'max_choices' => $optionGroup->max_choices,
                'pricing_mode' => $optionGroup->pricing_mode->value,
                'allows_quantity' => $optionGroup->allows_quantity,
                'is_active' => $optionGroup->is_active,
                'options' => $optionGroup->options->map(fn (Option $option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'description' => $option->description,
                    'price' => Money::toInput($option->price_cents),
                    'is_active' => $option->is_active,
                ]),
            ],
            'pricingModes' => $this->pricingModes(),
        ]);
    }

    public function update(OptionGroupRequest $request, OptionGroup $optionGroup): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($optionGroup);

        DB::transaction(function () use ($request, $optionGroup): void {
            $optionGroup->update($request->safe()->except('options'));
            $this->syncOptions($optionGroup, $request->validated('options'));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo de opções atualizado.']);

        return to_route('catalog.option-groups.index');
    }

    public function destroy(OptionGroup $optionGroup): RedirectResponse
    {
        $this->ensureBelongsToCurrentStore($optionGroup);

        $optionGroup->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo de opções excluído.']);

        return to_route('catalog.option-groups.index');
    }

    /**
     * Updates options that came with an id, creates the new ones and removes the rest.
     *
     * @param  array<int, array{id?: int|null, name: string, description?: string|null, price_cents: int, is_active?: bool}>  $options
     */
    private function syncOptions(OptionGroup $group, array $options): void
    {
        $keptIds = [];

        foreach (array_values($options) as $position => $data) {
            $attributes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'price_cents' => $data['price_cents'],
                'is_active' => $data['is_active'] ?? true,
                'sort_order' => $position,
            ];

            $existing = isset($data['id']) ? $group->options()->whereKey($data['id'])->first() : null;

            if ($existing !== null) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;
            } else {
                $keptIds[] = $group->options()->create($attributes)->id;
            }
        }

        $group->options()->whereKeyNot($keptIds)->delete();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function pricingModes(): array
    {
        return array_map(
            fn (PricingMode $mode) => ['value' => $mode->value, 'label' => $mode->label()],
            PricingMode::cases(),
        );
    }
}
