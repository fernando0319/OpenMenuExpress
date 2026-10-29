import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { formatCents } from '@/lib/money';
import { groupPriceCents } from '@/lib/pricing';
import type {
    CartItem,
    CartOption,
    MenuOptionGroup,
    MenuProduct,
    PizzaFlavorOption,
} from '@/types/menu';

/** "½ ", "⅓ ", "¼ " like App\Services\Catalog\PizzaMenu::fraction. */
export function pizzaFraction(count: number): string {
    switch (count) {
        case 1:
            return '';
        case 2:
            return '½ ';
        case 3:
            return '⅓ ';
        case 4:
            return '¼ ';
        default:
            return `1/${count} `;
    }
}

/**
 * Choices for one product (options, quantity, notes) and the price preview.
 * Shared by the customer menu and the counter screens; the server re-checks everything.
 */
export function useProductOptions(product: Ref<MenuProduct | null>) {
    /** groupId → optionId → quantity */
    const selections = ref<Record<number, Record<number, number>>>({});
    const quantity = ref(1);
    const notes = ref('');
    /** Pizza sizes: flavors in the order chosen, and the crust. */
    const pizzaFlavorIds = ref<number[]>([]);
    const pizzaCrustId = ref<number | null>(null);

    function reset(): void {
        selections.value = {};
        quantity.value = 1;
        notes.value = '';
        pizzaFlavorIds.value = [];
        pizzaCrustId.value = null;
    }

    const pizza = computed(() => product.value?.pizza ?? null);

    const pizzaFlavors = computed(() => {
        const all = new Map<number, PizzaFlavorOption & { kind: string }>();

        for (const group of pizza.value?.groups ?? []) {
            for (const flavor of group.flavors) {
                all.set(flavor.id, { ...flavor, kind: group.kind });
            }
        }

        return pizzaFlavorIds.value
            .map((id) => all.get(id))
            .filter((flavor) => flavor !== undefined);
    });

    /** Why a flavor cannot be added now (full pizza, or sweet with savory when the store does not mix). */
    function flavorBlockedReason(
        flavorId: number,
        kind: string,
    ): string | null {
        if (!pizza.value || pizzaFlavorIds.value.includes(flavorId)) {
            return null;
        }

        if (pizzaFlavorIds.value.length >= pizza.value.maxFlavors) {
            return pizza.value.maxFlavors === 1
                ? 'Troque o sabor'
                : `Até ${pizza.value.maxFlavors} sabores`;
        }

        if (
            !pizza.value.mixKinds &&
            pizzaFlavors.value.some((flavor) => flavor.kind !== kind)
        ) {
            return 'Não mistura doce e salgada';
        }

        return null;
    }

    function toggleFlavor(flavorId: number, kind: string): void {
        if (!pizza.value) {
            return;
        }

        if (pizzaFlavorIds.value.includes(flavorId)) {
            pizzaFlavorIds.value = pizzaFlavorIds.value.filter(
                (id) => id !== flavorId,
            );

            return;
        }

        // One flavor only: a tap swaps it.
        if (pizza.value.maxFlavors === 1) {
            pizzaFlavorIds.value = [flavorId];

            return;
        }

        if (flavorBlockedReason(flavorId, kind) === null) {
            pizzaFlavorIds.value = [...pizzaFlavorIds.value, flavorId];
        }
    }

    const pizzaCents = computed(() => {
        if (!pizza.value || pizzaFlavors.value.length === 0) {
            return 0;
        }

        const prices = pizzaFlavors.value.map((flavor) => flavor.priceCents);
        const flavorsCents =
            pizza.value.pricing === 'highest'
                ? Math.max(...prices)
                : Math.round(
                      prices.reduce((sum, price) => sum + price, 0) /
                          prices.length,
                  );
        const crust = pizza.value.crusts.find(
            (item) => item.id === pizzaCrustId.value,
        );

        return flavorsCents + (crust?.priceCents ?? 0);
    });

    watch(product, reset);

    function chosen(group: MenuOptionGroup): Record<number, number> {
        return selections.value[group.id] ?? {};
    }

    function countIn(group: MenuOptionGroup): number {
        return Object.values(chosen(group)).reduce((sum, qty) => sum + qty, 0);
    }

    function isSingleChoice(group: MenuOptionGroup): boolean {
        return group.maxChoices === 1 && !group.allowsQuantity;
    }

    function isChosen(group: MenuOptionGroup, optionId: number): boolean {
        return Boolean(chosen(group)[optionId]);
    }

    function isBlocked(group: MenuOptionGroup, optionId: number): boolean {
        return (
            !isChosen(group, optionId) &&
            !isSingleChoice(group) &&
            countIn(group) >= group.maxChoices
        );
    }

    function toggle(group: MenuOptionGroup, optionId: number): void {
        const current = { ...chosen(group) };

        if (isSingleChoice(group)) {
            selections.value[group.id] = current[optionId]
                ? {}
                : { [optionId]: 1 };

            return;
        }

        if (current[optionId]) {
            delete current[optionId];
        } else if (countIn(group) < group.maxChoices) {
            current[optionId] = 1;
        }

        selections.value[group.id] = current;
    }

    function changeOptionQuantity(
        group: MenuOptionGroup,
        optionId: number,
        delta: 1 | -1,
    ): void {
        const current = { ...chosen(group) };
        const next = (current[optionId] ?? 0) + delta;

        if (delta > 0 && countIn(group) >= group.maxChoices) {
            return;
        }

        if (next <= 0) {
            delete current[optionId];
        } else {
            current[optionId] = next;
        }

        selections.value[group.id] = current;
    }

    function ruleLabel(group: MenuOptionGroup): string {
        if (group.minChoices === 0) {
            return group.maxChoices === 1
                ? 'Opcional'
                : `Opcional, até ${group.maxChoices}`;
        }

        if (group.minChoices === group.maxChoices) {
            return `Escolha ${group.minChoices}`;
        }

        return `Escolha de ${group.minChoices} a ${group.maxChoices}`;
    }

    function optionPriceLabel(
        group: MenuOptionGroup,
        priceCents: number,
    ): string {
        if (priceCents === 0) {
            return '';
        }

        return group.pricingMode === 'sum'
            ? `+ ${formatCents(priceCents)}`
            : formatCents(priceCents);
    }

    const missingGroups = computed(() => {
        const groups =
            product.value?.optionGroups.filter(
                (group) => countIn(group) < group.minChoices,
            ) ?? [];

        // A pizza needs at least one flavor; it counts as a missing choice.
        return pizza.value && pizzaFlavorIds.value.length === 0
            ? [{ id: 0, name: 'Sabores' } as MenuOptionGroup, ...groups]
            : groups;
    });

    const unitCents = computed(() => {
        if (!product.value) {
            return 0;
        }

        return product.value.optionGroups.reduce(
            (sum, group) => {
                const selected = group.options
                    .filter((option) => chosen(group)[option.id])
                    .map((option) => ({
                        priceCents: option.priceCents,
                        quantity: chosen(group)[option.id],
                    }));

                return sum + groupPriceCents(group.pricingMode, selected);
            },
            pizza.value ? pizzaCents.value : product.value.effectivePriceCents,
        );
    });

    const totalCents = computed(() => unitCents.value * quantity.value);

    /**
     * The cart line for the current choices, or null while a required group is missing.
     */
    function buildItem(): Omit<CartItem, 'key'> | null {
        const current = product.value;

        if (!current || missingGroups.value.length > 0) {
            return null;
        }

        const options: CartOption[] = current.optionGroups.flatMap((group) =>
            group.options
                .filter((option) => chosen(group)[option.id])
                .map((option) => ({
                    optionId: option.id,
                    groupId: group.id,
                    groupName: group.name,
                    name: option.name,
                    quantity: chosen(group)[option.id],
                })),
        );

        const crust =
            pizza.value?.crusts.find(
                (item) => item.id === pizzaCrustId.value,
            ) ?? null;
        const fraction = pizzaFraction(pizzaFlavors.value.length);

        return {
            productId: current.id,
            name: current.name,
            imageUrl: current.imageUrl,
            quantity: quantity.value,
            notes: notes.value.trim(),
            options,
            unitCents: unitCents.value,
            pizza: pizza.value
                ? {
                      flavorIds: [...pizzaFlavorIds.value],
                      crustId: crust?.id ?? null,
                      flavorLabels: pizzaFlavors.value.map(
                          (flavor) => `${fraction}${flavor.name}`,
                      ),
                      crustLabel: crust?.name ?? null,
                  }
                : null,
        };
    }

    return {
        quantity,
        notes,
        chosen,
        countIn,
        isSingleChoice,
        isChosen,
        isBlocked,
        toggle,
        changeOptionQuantity,
        ruleLabel,
        optionPriceLabel,
        missingGroups,
        unitCents,
        totalCents,
        buildItem,
        reset,
        pizza,
        pizzaFlavorIds,
        pizzaFlavors,
        pizzaCrustId,
        toggleFlavor,
        flavorBlockedReason,
    };
}
