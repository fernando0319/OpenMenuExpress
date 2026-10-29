<script setup lang="ts">
import { ChevronRight, Heart, UtensilsCrossed } from '@lucide/vue';
import { computed } from 'vue';
import { formatCents } from '@/lib/money';
import type { MenuProduct } from '@/types/menu';

/**
 * A product on the customer menu: "tile" for the popular carousel, "row" for category lists.
 */
const props = withDefaults(
    defineProps<{
        product: MenuProduct;
        favorite: boolean;
        variant?: 'tile' | 'row';
    }>(),
    { variant: 'row' },
);

const emit = defineEmits<{
    open: [];
    toggleFavorite: [];
}>();

const onSale = computed(
    () => props.product.effectivePriceCents < props.product.priceCents,
);

/** With priced required options the shown price is only where it starts. */
const fromPrice = computed(
    () =>
        Boolean(props.product.pizza) ||
        props.product.optionGroups.some(
            (group) =>
                group.minChoices > 0 &&
                group.options.some((option) => option.priceCents > 0),
        ),
);

/** The description, or for a pizza size its flavors, slices and diameter. */
const summary = computed(() => {
    const pizza = props.product.pizza;

    if (!pizza) {
        return props.product.description;
    }

    const meta = [
        pizza.maxFlavors === 1 ? '1 sabor' : `até ${pizza.maxFlavors} sabores`,
        pizza.slices ? `${pizza.slices} fatias` : null,
        pizza.diameterCm ? `${pizza.diameterCm} cm` : null,
    ]
        .filter(Boolean)
        .join(' · ');

    return props.product.description
        ? `${meta} — ${props.product.description}`
        : meta;
});
</script>

<template>
    <article
        v-if="variant === 'tile'"
        class="relative flex w-44 shrink-0 snap-start flex-col rounded-3xl bg-white p-4 shadow-soft"
        :class="{ 'opacity-60': product.soldOut }"
    >
        <button
            type="button"
            class="absolute top-3 right-3 z-10 rounded-full p-1"
            :aria-label="
                favorite
                    ? `Tirar ${product.name} dos favoritos`
                    : `Favoritar ${product.name}`
            "
            @click="emit('toggleFavorite')"
        >
            <Heart
                class="size-5"
                :class="
                    favorite ? 'fill-rose-500 text-rose-500' : 'text-slate-300'
                "
            />
        </button>

        <button
            type="button"
            class="flex flex-1 flex-col text-left"
            :disabled="product.soldOut"
            @click="emit('open')"
        >
            <span
                class="mx-auto mt-2 grid size-28 place-items-center overflow-hidden rounded-full bg-brand-surface shadow-[0_10px_30px_-8px_rgb(91_33_182/0.35)] ring-4 ring-white"
            >
                <img
                    v-if="product.imageUrl"
                    :src="product.imageUrl"
                    :alt="product.name"
                    class="size-full object-cover"
                    loading="lazy"
                />
                <UtensilsCrossed v-else class="size-10 text-brand/40" />
            </span>
            <span class="mt-4 line-clamp-1 font-medium text-brand-ink">{{
                product.name
            }}</span>
            <span
                v-if="summary"
                class="line-clamp-1 text-[11px] text-muted-foreground"
                >{{ summary }}</span
            >
            <span class="mt-2 flex items-end justify-between gap-2">
                <span class="flex flex-col">
                    <span
                        v-if="onSale"
                        class="text-[11px] text-muted-foreground line-through"
                        >{{ formatCents(product.priceCents) }}</span
                    >
                    <span class="font-semibold text-brand-ink">
                        <span
                            v-if="fromPrice"
                            class="text-[10px] font-normal text-muted-foreground"
                            >a partir de
                        </span>
                        {{ formatCents(product.effectivePriceCents) }}
                    </span>
                </span>
                <span
                    class="grid size-8 place-items-center rounded-full bg-brand-surface text-brand-ink"
                >
                    <ChevronRight class="size-4" />
                </span>
            </span>
            <span
                v-if="product.soldOut"
                class="mt-2 text-xs font-medium text-rose-500"
                >Esgotado</span
            >
        </button>
    </article>

    <article
        v-else
        class="relative flex items-center gap-3 rounded-3xl bg-white p-3 shadow-soft"
        :class="{ 'opacity-60': product.soldOut }"
    >
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :disabled="product.soldOut"
            @click="emit('open')"
        >
            <span
                class="grid size-24 shrink-0 place-items-center overflow-hidden rounded-2xl bg-brand-surface"
            >
                <img
                    v-if="product.imageUrl"
                    :src="product.imageUrl"
                    :alt="product.name"
                    class="size-full object-cover"
                    loading="lazy"
                />
                <UtensilsCrossed v-else class="size-8 text-brand/40" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5 pr-6">
                <span
                    v-if="product.isFeatured"
                    class="text-[10px] font-medium tracking-wide text-brand-accent uppercase"
                    >Destaque</span
                >
                <span class="line-clamp-1 font-medium text-brand-ink">{{
                    product.name
                }}</span>
                <span
                    v-if="summary"
                    class="line-clamp-2 text-xs text-muted-foreground"
                    >{{ summary }}</span
                >
                <span class="mt-1 flex items-center gap-2">
                    <span
                        v-if="onSale"
                        class="text-xs text-muted-foreground line-through"
                        >{{ formatCents(product.priceCents) }}</span
                    >
                    <span class="font-semibold text-brand-ink">
                        <span
                            v-if="fromPrice"
                            class="text-[10px] font-normal text-muted-foreground"
                            >a partir de
                        </span>
                        {{ formatCents(product.effectivePriceCents) }}
                    </span>
                    <span
                        v-if="product.soldOut"
                        class="text-xs font-medium text-rose-500"
                        >Esgotado</span
                    >
                </span>
            </span>
        </button>
        <button
            type="button"
            class="absolute top-3 right-3 rounded-full p-1"
            :aria-label="
                favorite
                    ? `Tirar ${product.name} dos favoritos`
                    : `Favoritar ${product.name}`
            "
            @click="emit('toggleFavorite')"
        >
            <Heart
                class="size-5"
                :class="
                    favorite ? 'fill-rose-500 text-rose-500' : 'text-slate-300'
                "
            />
        </button>
        <ChevronRight
            class="absolute right-4 bottom-4 size-4 text-slate-400"
            aria-hidden="true"
        />
    </article>
</template>
