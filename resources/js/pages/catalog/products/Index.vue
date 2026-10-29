<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Star, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import ProductController from '@/actions/App/Http/Controllers/Catalog/ProductController';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { index as categoriesIndex } from '@/routes/catalog/categories';
import { create, edit, index } from '@/routes/catalog/products';
import type { Paginated, SelectOption } from '@/types';

type ProductRow = {
    id: number;
    name: string;
    category: string;
    price_cents: number;
    effective_price_cents: number;
    is_active: boolean;
    is_featured: boolean;
    is_pizza: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Produtos', href: index() }],
    },
});

const props = defineProps<{
    products: Paginated<ProductRow>;
    categories: SelectOption[];
    filters: { category: number | null; search: string };
}>();

const search = ref(props.filters.search);
const category = ref<number | null>(props.filters.category);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function applyFilters(): void {
    router.get(
        index.url({
            query: {
                search: search.value || undefined,
                category: category.value ?? undefined,
            },
        }),
        {},
        { preserveState: true, replace: true },
    );
}

watch(category, applyFilters);
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 300);
});

function destroy(product: ProductRow): void {
    if (!confirm(`Excluir o produto "${product.name}"?`)) {
        return;
    }

    router.delete(ProductController.destroy.url(product.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Produtos" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Produtos"
                description="Tudo o que aparece no seu cardápio."
            />
            <Button v-if="categories.length > 0" as-child>
                <Link :href="create()">
                    <Plus />
                    Novo produto
                </Link>
            </Button>
        </div>

        <div
            v-if="categories.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Antes de cadastrar produtos, crie pelo menos uma
            <Link :href="categoriesIndex()" class="underline">categoria</Link>.
        </div>

        <template v-else>
            <div class="flex flex-col gap-3 sm:flex-row">
                <Input
                    v-model="search"
                    placeholder="Buscar pelo nome"
                    aria-label="Buscar produto pelo nome"
                    class="sm:max-w-xs"
                />
                <NativeSelect
                    v-model="category"
                    aria-label="Filtrar por categoria"
                    class="sm:max-w-xs"
                >
                    <option :value="null">Todas as categorias</option>
                    <option
                        v-for="item in categories"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </NativeSelect>
            </div>

            <div
                v-if="products.data.length === 0"
                class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                Nenhum produto encontrado.
            </div>

            <div v-else class="overflow-hidden rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Produto</th>
                            <th class="px-4 py-2 font-medium">Categoria</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Preço
                            </th>
                            <th class="px-4 py-2 font-medium">Situação</th>
                            <th class="px-4 py-2">
                                <span class="sr-only">Ações</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="product in products.data"
                            :key="product.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 font-medium">
                                <span class="inline-flex items-center gap-1">
                                    <Star
                                        v-if="product.is_featured"
                                        class="size-3.5 fill-current text-amber-500"
                                        aria-label="Destaque"
                                    />
                                    {{ product.name }}
                                    <Badge
                                        v-if="product.is_pizza"
                                        variant="secondary"
                                        >Pizza</Badge
                                    >
                                </span>
                            </td>
                            <td class="px-4 py-2">{{ product.category }}</td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <span
                                    v-if="product.is_pizza"
                                    class="text-muted-foreground"
                                    >Tabela de pizzas</span
                                >
                                <span
                                    v-else-if="
                                        product.effective_price_cents <
                                        product.price_cents
                                    "
                                    class="mr-1 text-xs text-muted-foreground line-through"
                                    >{{
                                        formatCents(product.price_cents)
                                    }}</span
                                >
                                <template v-if="!product.is_pizza">{{
                                    formatCents(product.effective_price_cents)
                                }}</template>
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        product.is_active
                                            ? 'default'
                                            : 'secondary'
                                    "
                                >
                                    {{
                                        product.is_active ? 'Ativo' : 'Inativo'
                                    }}
                                </Badge>
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                <Button variant="ghost" size="icon" as-child>
                                    <Link
                                        :href="edit(product.id)"
                                        :aria-label="`Editar ${product.name}`"
                                    >
                                        <Pencil />
                                    </Link>
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Excluir ${product.name}`"
                                    @click="destroy(product)"
                                >
                                    <Trash2 />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <nav
                v-if="products.last_page > 1"
                class="flex flex-wrap gap-1"
                aria-label="Paginação"
            >
                <template v-for="link in products.links" :key="link.label">
                    <Button
                        v-if="link.url"
                        :variant="link.active ? 'default' : 'outline'"
                        size="sm"
                        as-child
                    >
                        <Link
                            :href="link.url"
                            preserve-state
                            v-html="link.label"
                        />
                    </Button>
                </template>
            </nav>
        </template>
    </div>
</template>
