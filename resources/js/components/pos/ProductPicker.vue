<script setup lang="ts">
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProductDialog from '@/components/menu/ProductDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import type { CartItem, MenuCategory, MenuProduct } from '@/types/menu';

const props = withDefaults(
    defineProps<{
        categories: MenuCategory[];
        /** Focus the search box when shown (desktop counter). */
        autofocus?: boolean;
    }>(),
    { autofocus: false },
);

const emit = defineEmits<{
    add: [item: Omit<CartItem, 'key'>];
}>();

const search = ref('');
const categoryId = ref<number | null>(null);

function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const visibleProducts = computed(() => {
    const term = normalize(search.value.trim());

    return props.categories
        .filter(
            (category) =>
                categoryId.value === null || category.id === categoryId.value,
        )
        .flatMap((category) => category.products)
        .filter(
            (product) => term === '' || normalize(product.name).includes(term),
        );
});

const dialogProduct = ref<MenuProduct | null>(null);
const dialogOpen = ref(false);

function pick(product: MenuProduct): void {
    if (product.soldOut) {
        return;
    }

    if (product.optionGroups.length > 0 || product.pizza) {
        dialogProduct.value = product;
        dialogOpen.value = true;

        return;
    }

    emit('add', {
        productId: product.id,
        name: product.name,
        quantity: 1,
        notes: '',
        options: [],
        unitCents: product.effectivePriceCents,
    });
}
</script>

<template>
    <div class="flex min-w-0 flex-col gap-3">
        <div class="relative">
            <Search
                class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                v-focus="autofocus"
                type="search"
                placeholder="Buscar produto"
                class="pl-9"
                aria-label="Buscar produto"
            />
        </div>

        <nav class="flex gap-2 overflow-x-auto pb-1">
            <Button
                size="sm"
                :variant="categoryId === null ? 'default' : 'outline'"
                class="shrink-0"
                @click="categoryId = null"
            >
                Todos
            </Button>
            <Button
                v-for="category in categories"
                :key="category.id"
                size="sm"
                :variant="categoryId === category.id ? 'default' : 'outline'"
                class="shrink-0"
                @click="categoryId = category.id"
            >
                {{ category.name }}
            </Button>
        </nav>

        <p
            v-if="visibleProducts.length === 0"
            class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Nenhum produto encontrado.
        </p>

        <div
            v-else
            class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4"
        >
            <button
                v-for="product in visibleProducts"
                :key="product.id"
                type="button"
                class="flex flex-col overflow-hidden rounded-xl border text-left transition-colors hover:border-primary disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="product.soldOut"
                @click="pick(product)"
            >
                <img
                    v-if="product.imageUrl"
                    :src="product.imageUrl"
                    :alt="product.name"
                    class="aspect-[4/3] w-full object-cover"
                    loading="lazy"
                />
                <span class="flex flex-1 flex-col gap-1 p-3">
                    <span class="line-clamp-2 text-sm font-medium">{{
                        product.name
                    }}</span>
                    <span class="mt-auto text-sm text-muted-foreground">
                        <template v-if="product.pizza">a partir de </template
                        >{{ formatCents(product.effectivePriceCents) }}
                    </span>
                    <Badge
                        v-if="product.soldOut"
                        variant="secondary"
                        class="self-start"
                        >Esgotado</Badge
                    >
                </span>
            </button>
        </div>

        <ProductDialog
            v-model:open="dialogOpen"
            :product="dialogProduct"
            :can-order="true"
            @add="emit('add', $event)"
        />
    </div>
</template>
