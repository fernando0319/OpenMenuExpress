<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { exportMethod as exportRoute, index } from '@/routes/ledger';
import { show as orderShow } from '@/routes/orders';
import type { Paginated } from '@/types';

type Entry = {
    id: number;
    occurredAt: string;
    kind: 'in' | 'out';
    method: string;
    asset: string;
    quantity: string | null;
    rate: string | null;
    brlCents: number;
    orderId: number | null;
    orderNumber: number | null;
    description: string | null;
};

const props = defineProps<{
    month: string;
    totalsByAsset: {
        asset: string;
        label: string;
        quantity: string | null;
        brlCents: number;
        unknownQuantity: boolean;
    }[];
    totalsByMethod: {
        method: string;
        receivedCents: number;
        returnedCents: number;
    }[];
    entries: Paginated<Entry>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Recebimentos', href: index() }],
    },
});

const month = ref(props.month);

watch(month, (value) => {
    if (/^\d{4}-\d{2}$/.test(value)) {
        router.get(
            index.url(),
            { month: value },
            { preserveScroll: true, preserveState: true },
        );
    }
});

const exportUrl = computed(() =>
    exportRoute.url({ query: { month: props.month } }),
);

const totalCents = computed(() =>
    props.totalsByAsset.reduce((sum, row) => sum + row.brlCents, 0),
);

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Recebimentos" />

    <div class="flex max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                title="Recebimentos"
                description="Tudo o que entrou e saiu, em cada moeda, com o valor em reais no momento."
            />
            <div class="ml-auto flex flex-wrap items-center gap-2">
                <Input
                    v-model="month"
                    type="month"
                    class="w-44"
                    aria-label="Mês"
                />
                <Button as-child variant="outline">
                    <a :href="exportUrl">
                        <Download />
                        Exportar (Excel)
                    </a>
                </Button>
            </div>
        </div>

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-sm text-muted-foreground">Saldo do mês</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ formatCents(totalCents) }}
                </p>
            </div>
            <div
                v-for="row in totalsByAsset"
                :key="row.asset"
                class="rounded-xl border bg-card p-4"
            >
                <p class="text-sm text-muted-foreground">{{ row.label }}</p>
                <p class="text-2xl font-semibold tabular-nums">
                    {{ formatCents(row.brlCents) }}
                </p>
                <p
                    v-if="row.quantity !== null"
                    class="font-mono text-sm text-muted-foreground"
                >
                    {{ row.quantity }} {{ row.asset }}
                </p>
                <p
                    v-if="row.unknownQuantity"
                    class="text-xs text-amber-700 dark:text-amber-300"
                >
                    Há recebimentos sem a quantidade informada pelo provedor.
                </p>
            </div>
        </section>

        <section
            v-if="totalsByMethod.length > 0"
            class="overflow-x-auto rounded-xl border"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Forma</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Entradas
                        </th>
                        <th class="px-4 py-2 text-right font-medium">Saídas</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="row in totalsByMethod" :key="row.method">
                        <td class="px-4 py-2">{{ row.method }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ formatCents(row.receivedCents) }}
                        </td>
                        <td
                            class="px-4 py-2 text-right text-muted-foreground tabular-nums"
                        >
                            {{
                                row.returnedCents > 0
                                    ? formatCents(row.returnedCents)
                                    : '—'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="overflow-x-auto rounded-xl border">
            <p
                v-if="entries.data.length === 0"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                Nada recebido neste mês.
            </p>
            <table v-else class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Data</th>
                        <th class="px-4 py-2 font-medium">Forma</th>
                        <th class="px-4 py-2 font-medium">Quantidade</th>
                        <th class="px-4 py-2 text-right font-medium">Valor</th>
                        <th class="px-4 py-2 font-medium">Pedido</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="entry in entries.data" :key="entry.id">
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ dateTime.format(new Date(entry.occurredAt)) }}
                        </td>
                        <td class="px-4 py-2">
                            {{ entry.method }}
                            <Badge
                                v-if="entry.kind === 'out'"
                                variant="outline"
                                class="ml-1"
                                >Saída</Badge
                            >
                            <p
                                v-if="entry.description"
                                class="text-xs text-muted-foreground"
                            >
                                {{ entry.description }}
                            </p>
                        </td>
                        <td class="px-4 py-2 font-mono text-xs">
                            <template v-if="entry.quantity !== null">
                                {{ entry.quantity }} {{ entry.asset }}
                                <span
                                    v-if="entry.rate"
                                    class="block text-muted-foreground"
                                    >a
                                    {{
                                        formatCents(
                                            Math.round(
                                                Number(entry.rate) * 100,
                                            ),
                                        )
                                    }}
                                    / {{ entry.asset }}</span
                                >
                            </template>
                            <template v-else-if="entry.asset !== 'BRL'"
                                >{{ entry.asset }} · a confirmar</template
                            >
                        </td>
                        <td
                            class="px-4 py-2 text-right whitespace-nowrap tabular-nums"
                            :class="
                                entry.brlCents < 0 ? 'text-destructive' : ''
                            "
                        >
                            {{ formatCents(entry.brlCents) }}
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                v-if="entry.orderId"
                                :href="orderShow(entry.orderId)"
                                class="underline-offset-4 hover:underline"
                                >nº {{ entry.orderNumber }}</Link
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <nav
            v-if="entries.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in entries.links" :key="link.label">
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
