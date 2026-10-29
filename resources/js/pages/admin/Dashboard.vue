<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index as storesIndex, show as storeShow } from '@/routes/admin/stores';

type AuditRow = {
    id: number;
    action: string;
    store: string | null;
    storeId: number | null;
    by: string | null;
    details: Record<string, unknown> | null;
    at: string | null;
};

const props = defineProps<{
    totals: Record<string, number>;
    daily: { date: string; ordersCount: number; gmvCents: number }[];
    newestStores: {
        id: number;
        name: string;
        slug: string;
        createdAt: string | null;
    }[];
    audit: AuditRow[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Administração', href: dashboard() }],
    },
});

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
const day = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'UTC',
});

function formatDate(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '';
}

const cards = computed(() => [
    {
        label: 'Lojas',
        value: String(props.totals.storesTotal),
        hint: `${props.totals.storesNew30d} novas em 30 dias`,
    },
    {
        label: 'Lojas ativas (7 dias)',
        value: String(props.totals.storesActive7d),
        hint: `${props.totals.storesSuspended} suspensa(s)`,
    },
    {
        label: 'Pedidos hoje',
        value: String(props.totals.ordersToday),
        hint: `${props.totals.orders30d} em 30 dias`,
    },
    {
        label: 'Vendas em 30 dias',
        value: formatCents(props.totals.gmv30dCents),
        hint: `${props.totals.usersTotal} usuários`,
    },
]);

const orderBars = computed(() =>
    props.daily.map((row) => {
        const date = new Date(`${row.date}T00:00:00Z`);

        return {
            label: day.format(date),
            value: row.ordersCount,
            title: `${day.format(date)}: ${row.ordersCount} pedido(s), ${formatCents(row.gmvCents)}`,
        };
    }),
);
</script>

<template>
    <Head title="Administração" />

    <div class="flex max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                title="Administração"
                description="Visão geral das lojas hospedadas na plataforma."
            />
            <Button as-child class="ml-auto">
                <Link :href="storesIndex()">Ver lojas</Link>
            </Button>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-xl border p-4"
            >
                <p class="text-sm text-muted-foreground">{{ card.label }}</p>
                <p class="text-2xl font-semibold">{{ card.value }}</p>
                <p class="text-xs text-muted-foreground">{{ card.hint }}</p>
            </div>
        </div>

        <div
            v-if="totals.messageFailures24h > 0 || totals.printFailures24h > 0"
            class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-900 dark:bg-amber-950"
        >
            Nas últimas 24 horas: {{ totals.messageFailures24h }} mensagem(ns)
            de WhatsApp falharam e {{ totals.printFailures24h }} impressão(ões)
            não saíram. Veja os detalhes em cada loja.
        </div>

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-medium">Pedidos por dia (30 dias)</h2>
            <BarChart :items="orderBars" :label-every="5" />
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border text-sm">
                <h2 class="border-b p-4 font-medium">Lojas mais recentes</h2>
                <ul class="divide-y">
                    <li
                        v-for="store in newestStores"
                        :key="store.id"
                        class="flex justify-between gap-3 px-4 py-2"
                    >
                        <Link
                            :href="storeShow(store.id)"
                            class="font-medium underline-offset-4 hover:underline"
                            >{{ store.name }}</Link
                        >
                        <span class="text-muted-foreground">{{
                            formatDate(store.createdAt)
                        }}</span>
                    </li>
                </ul>
            </section>
            <section class="rounded-xl border text-sm">
                <h2 class="border-b p-4 font-medium">Intervenções recentes</h2>
                <p v-if="audit.length === 0" class="p-4 text-muted-foreground">
                    Nenhuma intervenção registrada.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="row in audit"
                        :key="row.id"
                        class="flex flex-col px-4 py-2"
                    >
                        <span>
                            <strong>{{ row.action }}</strong>
                            <template v-if="row.store">
                                ·
                                <Link
                                    v-if="row.storeId"
                                    :href="storeShow(row.storeId)"
                                    class="underline-offset-4 hover:underline"
                                    >{{ row.store }}</Link
                                >
                            </template>
                        </span>
                        <span class="text-xs text-muted-foreground"
                            >{{ formatDate(row.at) }} ·
                            {{ row.by ?? '—' }}</span
                        >
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
