<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes/admin';
import { index as networkIndex } from '@/routes/admin/network';
import { index, show } from '@/routes/admin/network/couriers';
import type { Paginated } from '@/types';

type CourierRow = {
    id: number;
    name: string;
    city: string;
    vehicle: string;
    status: 'pending' | 'approved' | 'rejected' | 'blocked';
    statusLabel: string;
    online: boolean;
    createdAt: string | null;
};

const props = defineProps<{
    couriers: Paginated<CourierRow>;
    filters: { status: string; search: string };
    pendingCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Rede de entregadores', href: networkIndex() },
            { title: 'Entregadores', href: index() },
        ],
    },
});

const statuses = [
    { value: 'pending', label: 'Para analisar' },
    { value: 'approved', label: 'Aprovados' },
    { value: 'blocked', label: 'Bloqueados' },
    { value: 'rejected', label: 'Recusados' },
    { value: 'all', label: 'Todos' },
];

const search = ref(props.filters.search);

function filter(status: string = props.filters.status): void {
    router.get(
        index.url({
            query: {
                status: status === 'pending' ? undefined : status,
                search: search.value.trim() || undefined,
            },
        }),
        {},
        { preserveScroll: true, replace: true },
    );
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

function badge(
    status: CourierRow['status'],
): 'default' | 'secondary' | 'destructive' | 'outline' {
    return {
        pending: 'outline',
        approved: 'secondary',
        rejected: 'destructive',
        blocked: 'destructive',
    }[status] as 'outline' | 'secondary' | 'destructive';
}
</script>

<template>
    <Head title="Entregadores da rede" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Entregadores"
            description="Confira o documento e os dados antes de aprovar. Só aprovados recebem corridas."
        />

        <div class="flex flex-wrap items-center gap-3">
            <div
                class="flex gap-1 rounded-xl bg-muted p-1"
                role="tablist"
                aria-label="Situação"
            >
                <button
                    v-for="option in statuses"
                    :key="option.value"
                    type="button"
                    role="tab"
                    :aria-selected="filters.status === option.value"
                    class="rounded-lg px-3 py-1.5 text-sm font-medium transition"
                    :class="
                        filters.status === option.value
                            ? 'bg-background shadow-xs'
                            : 'text-muted-foreground'
                    "
                    @click="filter(option.value)"
                >
                    {{ option.label }}
                    <template
                        v-if="option.value === 'pending' && pendingCount > 0"
                    >
                        ({{ pendingCount }})</template
                    >
                </button>
            </div>
            <form class="ml-auto" @submit.prevent="filter()">
                <Input
                    v-model="search"
                    placeholder="Nome, cidade ou CPF"
                    class="w-64"
                />
            </form>
        </div>

        <p
            v-if="couriers.data.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhum entregador aqui.
        </p>

        <div
            v-else
            class="overflow-x-auto rounded-2xl border bg-card shadow-xs"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Nome</th>
                        <th class="px-4 py-2.5 font-medium">Cidade</th>
                        <th class="px-4 py-2.5 font-medium">Veículo</th>
                        <th class="px-4 py-2.5 font-medium">Cadastro</th>
                        <th class="px-4 py-2.5 font-medium">Situação</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="courier in couriers.data"
                        :key="courier.id"
                        class="hover:bg-muted/30"
                    >
                        <td class="px-4 py-2.5">
                            <Link
                                :href="show(courier.id)"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ courier.name }}</Link
                            >
                            <span
                                v-if="courier.online"
                                class="ml-2 inline-block size-2 rounded-full bg-emerald-500"
                                title="Online"
                            />
                        </td>
                        <td class="px-4 py-2.5">{{ courier.city }}</td>
                        <td class="px-4 py-2.5">{{ courier.vehicle }}</td>
                        <td class="px-4 py-2.5">
                            {{
                                courier.createdAt
                                    ? date.format(new Date(courier.createdAt))
                                    : ''
                            }}
                        </td>
                        <td class="px-4 py-2.5">
                            <Badge :variant="badge(courier.status)">{{
                                courier.statusLabel
                            }}</Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="couriers.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in couriers.links" :key="link.label">
                <Button
                    v-if="link.url"
                    :variant="link.active ? 'default' : 'outline'"
                    size="sm"
                    as-child
                >
                    <Link :href="link.url" preserve-state v-html="link.label" />
                </Button>
            </template>
        </nav>
    </div>
</template>
