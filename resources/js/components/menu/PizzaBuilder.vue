<script setup lang="ts">
import { Check, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { pizzaFraction } from '@/composables/useProductOptions';
import type { useProductOptions } from '@/composables/useProductOptions';
import { formatCents } from '@/lib/money';
import type { PizzaInfo } from '@/types/menu';

/**
 * Builds one pizza: flavors by category (each part of the pizza), crust, and a drawing
 * of the pizza divided as the customer picks. Used by the menu, the PDV and the waiter.
 */
const props = defineProps<{
    pizza: PizzaInfo;
    options: ReturnType<typeof useProductOptions>;
}>();

const groupId = ref<number | null>(props.pizza.groups[0]?.id ?? null);
const search = ref('');

watch(
    () => props.pizza,
    (pizza) => {
        groupId.value = pizza.groups[0]?.id ?? null;
        search.value = '';
    },
);

function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const visibleGroups = computed(() => {
    const term = normalize(search.value.trim());

    if (term !== '') {
        return props.pizza.groups
            .map((group) => ({
                ...group,
                flavors: group.flavors.filter(
                    (flavor) =>
                        normalize(flavor.name).includes(term) ||
                        normalize(flavor.description ?? '').includes(term),
                ),
            }))
            .filter((group) => group.flavors.length > 0);
    }

    return props.pizza.groups.filter((group) => group.id === groupId.value);
});

const chosen = computed(() => props.options.pizzaFlavors.value);
const fraction = computed(() => pizzaFraction(chosen.value.length));

const meta = computed(() =>
    [
        props.pizza.slices ? `${props.pizza.slices} fatias` : null,
        props.pizza.diameterCm ? `${props.pizza.diameterCm} cm` : null,
        props.pizza.maxFlavors === 1
            ? '1 sabor'
            : `até ${props.pizza.maxFlavors} sabores`,
    ]
        .filter(Boolean)
        .join(' · '),
);

const ruleHint = computed(() =>
    props.pizza.maxFlavors === 1
        ? ''
        : props.pizza.pricing === 'highest'
          ? 'Com mais de um sabor, vale o preço do sabor mais caro.'
          : 'Com mais de um sabor, o preço é a média dos sabores.',
);

/** SVG wedges for the chosen flavors (a whole pizza while nothing is chosen). */
const wedges = computed(() => {
    const count = Math.max(1, chosen.value.length);
    const radius = 46;

    return Array.from({ length: count }, (_, index) => {
        const start = (index / count) * Math.PI * 2 - Math.PI / 2;
        const end = ((index + 1) / count) * Math.PI * 2 - Math.PI / 2;
        const point = (angle: number) =>
            `${50 + radius * Math.cos(angle)} ${50 + radius * Math.sin(angle)}`;

        return {
            key: index,
            path:
                count === 1
                    ? ''
                    : `M 50 50 L ${point(start)} A ${radius} ${radius} 0 0 1 ${point(end)} Z`,
            tone: index % 2 === 0 ? 'fill-primary/25' : 'fill-primary/45',
        };
    });
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="flex items-center gap-4">
            <svg
                viewBox="0 0 100 100"
                class="size-24 shrink-0"
                role="img"
                :aria-label="
                    chosen.length === 0
                        ? 'Pizza sem sabor escolhido'
                        : `Pizza: ${chosen.map((flavor) => fraction + flavor.name).join(', ')}`
                "
            >
                <circle
                    cx="50"
                    cy="50"
                    r="49"
                    class="fill-amber-200 dark:fill-amber-900"
                />
                <circle
                    v-if="wedges.length === 1"
                    cx="50"
                    cy="50"
                    r="46"
                    :class="chosen.length ? 'fill-primary/30' : 'fill-muted'"
                />
                <path
                    v-for="wedge in wedges.length > 1 ? wedges : []"
                    :key="wedge.key"
                    :d="wedge.path"
                    :class="wedge.tone"
                    class="stroke-background"
                    stroke-width="2"
                />
            </svg>
            <div class="min-w-0 flex-1">
                <p class="text-sm text-muted-foreground">{{ meta }}</p>
                <ul v-if="chosen.length" class="mt-1 flex flex-col gap-1">
                    <li
                        v-for="flavor in chosen"
                        :key="flavor.id"
                        class="flex items-center gap-2 text-sm font-medium"
                    >
                        <span class="truncate"
                            >{{ fraction }}{{ flavor.name }}</span
                        >
                        <button
                            type="button"
                            class="grid size-6 shrink-0 place-items-center rounded-full text-muted-foreground hover:bg-muted"
                            :aria-label="`Tirar ${flavor.name}`"
                            @click="
                                options.toggleFlavor(flavor.id, flavor.kind)
                            "
                        >
                            <X class="size-3.5" />
                        </button>
                    </li>
                </ul>
                <p v-else class="mt-1 text-sm font-medium">
                    {{
                        pizza.maxFlavors === 1
                            ? 'Escolha o sabor'
                            : `Escolha de 1 a ${pizza.maxFlavors} sabores`
                    }}
                </p>
                <p v-if="ruleHint" class="mt-1 text-xs text-muted-foreground">
                    {{ ruleHint }}
                </p>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <div class="flex items-center justify-between gap-2">
                <h3 class="font-semibold">Sabores</h3>
                <span
                    class="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                    :class="
                        chosen.length === 0
                            ? 'bg-rose-50 text-rose-600 dark:bg-rose-950 dark:text-rose-300'
                            : 'bg-muted text-muted-foreground'
                    "
                    >{{ chosen.length }} de {{ pizza.maxFlavors }}</span
                >
            </div>
            <div class="relative">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="search"
                    type="search"
                    class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                    placeholder="Buscar sabor ou ingrediente"
                    aria-label="Buscar sabor"
                />
            </div>
            <div
                v-if="search.trim() === '' && pizza.groups.length > 1"
                class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1"
                role="tablist"
                aria-label="Categorias de sabores"
            >
                <button
                    v-for="group in pizza.groups"
                    :key="group.id"
                    type="button"
                    role="tab"
                    :aria-selected="group.id === groupId"
                    class="shrink-0 rounded-full border px-3 py-1.5 text-sm whitespace-nowrap transition-colors"
                    :class="
                        group.id === groupId
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-background'
                    "
                    @click="groupId = group.id"
                >
                    {{ group.name }}
                    <span class="opacity-80"
                        >· {{ formatCents(group.priceCents) }}</span
                    >
                </button>
            </div>

            <div
                v-for="group in visibleGroups"
                :key="group.id"
                class="flex flex-col gap-2"
            >
                <p
                    v-if="search.trim() !== ''"
                    class="text-xs font-medium text-muted-foreground"
                >
                    {{ group.name }}
                </p>
                <button
                    v-for="flavor in group.flavors"
                    :key="flavor.id"
                    type="button"
                    class="flex items-start gap-3 rounded-2xl border p-3 text-left transition-colors disabled:opacity-45"
                    :class="
                        options.pizzaFlavorIds.value.includes(flavor.id)
                            ? 'border-primary bg-primary/5'
                            : 'bg-background'
                    "
                    :disabled="
                        options.flavorBlockedReason(flavor.id, group.kind) !==
                            null && pizza.maxFlavors > 1
                    "
                    :aria-pressed="
                        options.pizzaFlavorIds.value.includes(flavor.id)
                    "
                    @click="options.toggleFlavor(flavor.id, group.kind)"
                >
                    <img
                        v-if="flavor.imageUrl"
                        :src="flavor.imageUrl"
                        :alt="flavor.name"
                        class="size-14 shrink-0 rounded-xl object-cover"
                        loading="lazy"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-medium">{{
                            flavor.name
                        }}</span>
                        <span
                            v-if="flavor.description"
                            class="block text-xs text-muted-foreground"
                            >{{ flavor.description }}</span
                        >
                        <span
                            v-if="
                                options.flavorBlockedReason(
                                    flavor.id,
                                    group.kind,
                                ) && pizza.maxFlavors > 1
                            "
                            class="mt-0.5 block text-[11px] text-muted-foreground"
                            >{{
                                options.flavorBlockedReason(
                                    flavor.id,
                                    group.kind,
                                )
                            }}</span
                        >
                    </span>
                    <span class="flex flex-col items-end gap-1">
                        <span class="text-xs whitespace-nowrap text-primary">{{
                            formatCents(flavor.priceCents)
                        }}</span>
                        <span
                            class="grid size-5 place-items-center rounded-md border-2"
                            :class="
                                options.pizzaFlavorIds.value.includes(flavor.id)
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-muted-foreground/40'
                            "
                        >
                            <Check
                                v-if="
                                    options.pizzaFlavorIds.value.includes(
                                        flavor.id,
                                    )
                                "
                                class="size-3"
                            />
                        </span>
                    </span>
                </button>
            </div>
            <p
                v-if="visibleGroups.length === 0"
                class="py-4 text-center text-sm text-muted-foreground"
            >
                Nenhum sabor encontrado.
            </p>
        </section>

        <section v-if="pizza.crusts.length > 0" class="flex flex-col gap-2">
            <h3 class="font-semibold">Borda</h3>
            <label
                class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3 text-sm"
                :class="
                    options.pizzaCrustId.value === null
                        ? 'border-primary bg-primary/5'
                        : 'bg-background'
                "
            >
                <input
                    v-model="options.pizzaCrustId.value"
                    type="radio"
                    :value="null"
                    class="size-4 accent-primary"
                    name="pizza-crust"
                />
                <span class="flex-1">Sem borda recheada</span>
            </label>
            <label
                v-for="crust in pizza.crusts"
                :key="crust.id"
                class="flex cursor-pointer items-center gap-3 rounded-2xl border p-3 text-sm"
                :class="
                    options.pizzaCrustId.value === crust.id
                        ? 'border-primary bg-primary/5'
                        : 'bg-background'
                "
            >
                <input
                    v-model="options.pizzaCrustId.value"
                    type="radio"
                    :value="crust.id"
                    class="size-4 accent-primary"
                    name="pizza-crust"
                />
                <span class="min-w-0 flex-1">
                    <span class="block">{{ crust.name }}</span>
                    <span
                        v-if="crust.description"
                        class="block text-xs text-muted-foreground"
                        >{{ crust.description }}</span
                    >
                </span>
                <span class="text-xs whitespace-nowrap text-primary"
                    >+ {{ formatCents(crust.priceCents) }}</span
                >
            </label>
        </section>
    </div>
</template>
