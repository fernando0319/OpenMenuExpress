<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/stores';
import type { Paginated } from '@/types/store';

type StoreRow = {
    id: number;
    name: string;
    slug: string;
    owner: string | null;
    membersCount: number;
    createdAt: string | null;
    lastOrderAt: string | null;
    orders30d: number;
    sales30dCents: number;
    whatsapp: boolean;
    isSuspended: boolean;
    subscription: string;
};

const props = defineProps<{
    stores: Paginated<StoreRow>;
    filters: { search: string; sort: string; status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Lojas', href: index() },
        ],
    },
});

const search = ref(props.filters.search);
const sort = ref(props.filters.sort);
const status = ref(props.filters.status);

function apply(): void {
    router.get(
        index.url(),
        { search: search.value, sort: sort.value, status: status.value },
        { preserveState: true, preserveScroll: true },
    );
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

function formatDate(value: string | null): string {
    return value ? date.format(new Date(value)) : '—';
}
</script>

<template>
    <Head title="Lojas" />

    <div class="flex flex-col gap-4 p-4">
        <Heading
            title="Lojas"
            :description="`${stores.total} loja(s) hospedada(s)`"
        />

        <form class="flex flex-wrap items-center gap-2" @submit.prevent="apply">
            <Input
                v-model="search"
                type="search"
                placeholder="Buscar por nome ou endereço"
                class="w-64"
                aria-label="Buscar loja"
            />
            <NativeSelect
                v-model="status"
                class="w-36"
                aria-label="Situação"
                @change="apply"
            >
                <option value="all">Todas</option>
                <option value="active">Ativas</option>
                <option value="suspended">Suspensas</option>
            </NativeSelect>
            <NativeSelect
                v-model="sort"
                class="w-48"
                aria-label="Ordenar por"
                @change="apply"
            >
                <option value="recent">Mais recentes</option>
                <option value="sales">Maior faturamento</option>
                <option value="orders">Mais pedidos</option>
                <option value="name">Nome</option>
            </NativeSelect>
            <Button type="submit" variant="outline">Buscar</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="p-3 font-medium">Loja</th>
                        <th class="p-3 font-medium">Dono</th>
                        <th class="p-3 text-right font-medium">
                            Pedidos (30d)
                        </th>
                        <th class="p-3 text-right font-medium">Vendas (30d)</th>
                        <th class="p-3 font-medium">Último pedido</th>
                        <th class="p-3 font-medium">Criada em</th>
                        <th class="p-3 font-medium">Situação</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="stores.data.length === 0">
                        <td
                            colspan="7"
                            class="p-6 text-center text-muted-foreground"
                        >
                            Nenhuma loja encontrada.
                        </td>
                    </tr>
                    <tr
                        v-for="store in stores.data"
                        :key="store.id"
                        class="hover:bg-muted/40"
                    >
                        <td class="p-3">
                            <Link
                                :href="show(store.id)"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ store.name }}</Link
                            >
                            <p class="text-xs text-muted-foreground">
                                /{{ store.slug }} ·
                                {{ store.membersCount }} membro(s)
                            </p>
                        </td>
                        <td class="p-3">{{ store.owner ?? '—' }}</td>
                        <td class="p-3 text-right">{{ store.orders30d }}</td>
                        <td class="p-3 text-right">
                            {{ formatCents(store.sales30dCents) }}
                        </td>
                        <td class="p-3">{{ formatDate(store.lastOrderAt) }}</td>
                        <td class="p-3">{{ formatDate(store.createdAt) }}</td>
                        <td class="p-3">
                            <div class="flex flex-wrap gap-1">
                                <Badge
                                    v-if="store.isSuspended"
                                    variant="destructive"
                                    >Suspensa</Badge
                                >
                                <Badge v-else variant="secondary">Ativa</Badge>
                                <Badge variant="outline">{{
                                    store.subscription
                                }}</Badge>
                                <Badge v-if="store.whatsapp" variant="outline"
                                    >WhatsApp</Badge
                                >
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="stores.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in stores.links" :key="link.label">
                <Button
                    v-if="link.url"
                    as-child
                    size="sm"
                    :variant="link.active ? 'default' : 'outline'"
                >
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <Link :href="link.url" preserve-scroll
                        ><span v-html="link.label"
                    /></Link>
                </Button>
            </template>
        </nav>
    </div>
</template>
