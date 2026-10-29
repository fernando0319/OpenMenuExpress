<script setup lang="ts">
import type { MenuCategory } from '@/types/menu';

/**
 * "Explorar categorias": square tiles with a photo from the category (or its initial).
 */
defineProps<{
    categories: MenuCategory[];
    activeId: number | null;
}>();

const emit = defineEmits<{
    select: [categoryId: number];
}>();

const pastels = [
    'bg-lime-100 text-lime-700',
    'bg-teal-100 text-teal-700',
    'bg-amber-100 text-amber-700',
    'bg-rose-100 text-rose-700',
    'bg-violet-100 text-violet-700',
    'bg-sky-100 text-sky-700',
];

function cover(category: MenuCategory): string | null {
    return (
        category.products.find((product) => product.imageUrl)?.imageUrl ?? null
    );
}
</script>

<template>
    <div
        class="-mx-4 flex snap-x scroll-px-4 [scrollbar-width:none] gap-4 overflow-x-auto px-4 pb-2 [&::-webkit-scrollbar]:hidden"
    >
        <button
            v-for="(category, position) in categories"
            :key="category.id"
            type="button"
            class="flex w-[4.75rem] shrink-0 snap-start flex-col items-center gap-2"
            :aria-pressed="activeId === category.id"
            @click="emit('select', category.id)"
        >
            <span
                class="grid size-[4.5rem] place-items-center overflow-hidden rounded-2xl text-2xl font-semibold transition-shadow"
                :class="[
                    pastels[position % pastels.length],
                    activeId === category.id
                        ? 'ring-2 ring-brand ring-offset-2'
                        : '',
                ]"
            >
                <img
                    v-if="cover(category)"
                    :src="cover(category) ?? ''"
                    :alt="category.name"
                    class="size-full object-cover"
                    loading="lazy"
                />
                <template v-else>{{
                    category.name.charAt(0).toUpperCase()
                }}</template>
            </span>
            <span
                class="line-clamp-2 text-center text-xs leading-tight"
                :class="
                    activeId === category.id
                        ? 'font-semibold text-brand'
                        : 'text-brand-ink'
                "
                >{{ category.name }}</span
            >
        </button>
    </div>
</template>
