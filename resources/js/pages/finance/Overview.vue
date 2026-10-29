<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertCircle,
    ArrowDownLeft,
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    Plus,
    Scale,
    ShoppingBag,
    Tags,
    Truck,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import InstallmentStatusBadge from '@/components/finance/InstallmentStatusBadge.vue';
import SettleDialog from '@/components/finance/SettleDialog.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatDate, paidWord } from '@/lib/finance';
import { formatCents } from '@/lib/money';
import {
    overview as overviewRoute,
    payables,
    receivables,
} from '@/routes/finance';
import { index as categoriesIndex } from '@/routes/finance/categories';
import { index as suppliersIndex } from '@/routes/finance/suppliers';
import { create, show } from '@/routes/finance/titles';
import type { CountAndCents, InstallmentRow, Option } from '@/types/finance';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Financeiro', href: overviewRoute() }],
    },
});

const props = defineProps<{
    month: string;
    monthLabel: string;
    isCurrentMonth: boolean;
    overview: {
        salesCents: number;
        receivedCents: number;
        paidCents: number;
        resultCents: number;
        toReceiveCents: number;
        toPayCents: number;
        overdue: { payable: CountAndCents; receivable: CountAndCents };
        expensesByCategory: { name: string; cents: number }[];
    };
    upcoming: InstallmentRow[];
    paymentMethods: Option[];
    today: string;
    cashOpen: boolean;
}>();

function shift(months: number): void {
    const [year, month] = props.month.split('-').map(Number);
    const target = new Date(Date.UTC(year, month - 1 + months, 1));
    const value = target.toISOString().slice(0, 7);

    router.get(
        overviewRoute.url({ query: { month: value } }),
        {},
        { preserveScroll: true },
    );
}

const maxCategory = computed(() =>
    Math.max(1, ...props.overview.expensesByCategory.map((row) => row.cents)),
);

const settleOpen = ref(false);
const settling = ref<InstallmentRow | null>(null);

function settle(row: InstallmentRow): void {
    settling.value = row;
    settleOpen.value = true;
}
</script>

<template>
    <Head title="Financeiro" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Financeiro"
                description="O dinheiro da loja no mês: vendas, contas pagas e recebidas, e o que ainda vence."
            />
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="create({ query: { type: 'receivable' } })"
                        ><Plus /> A receber</Link
                    >
                </Button>
                <Button as-child>
                    <Link :href="create({ query: { type: 'payable' } })"
                        ><Plus /> A pagar</Link
                    >
                </Button>
            </div>
        </div>

        <div
            v-if="
                overview.overdue.payable.count ||
                overview.overdue.receivable.count
            "
            class="flex flex-col gap-2 rounded-2xl border border-destructive/40 bg-destructive/5 p-4 text-sm sm:flex-row sm:items-center sm:gap-6"
            role="alert"
        >
            <AlertCircle
                class="size-5 shrink-0 text-destructive"
                aria-hidden="true"
            />
            <Link
                v-if="overview.overdue.payable.count"
                :href="payables({ query: { status: 'overdue' } })"
                class="hover:underline"
            >
                <strong
                    >{{ overview.overdue.payable.count }} conta(s) a pagar
                    vencida(s)</strong
                >
                · {{ formatCents(overview.overdue.payable.cents) }}
            </Link>
            <Link
                v-if="overview.overdue.receivable.count"
                :href="receivables({ query: { status: 'overdue' } })"
                class="hover:underline"
            >
                <strong
                    >{{ overview.overdue.receivable.count }} conta(s) a receber
                    atrasada(s)</strong
                >
                · {{ formatCents(overview.overdue.receivable.cents) }}
            </Link>
        </div>

        <div class="flex items-center gap-2">
            <Button
                variant="outline"
                size="icon"
                aria-label="Mês anterior"
                @click="shift(-1)"
                ><ChevronLeft
            /></Button>
            <h2 class="min-w-44 text-center font-semibold">{{ monthLabel }}</h2>
            <Button
                variant="outline"
                size="icon"
                aria-label="Próximo mês"
                @click="shift(1)"
                ><ChevronRight
            /></Button>
            <Button v-if="!isCurrentMonth" variant="ghost" size="sm" as-child>
                <Link :href="overviewRoute()">Mês atual</Link>
            </Button>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile
                label="Vendas no sistema"
                :value="formatCents(overview.salesCents)"
                :icon="ShoppingBag"
                hint="Pedidos e mesas concluídos"
            />
            <StatTile
                label="Outras receitas recebidas"
                :value="formatCents(overview.receivedCents)"
                :icon="ArrowDownLeft"
                hint="Contas a receber baixadas"
            />
            <StatTile
                label="Contas pagas"
                :value="formatCents(overview.paidCents)"
                :icon="ArrowUpRight"
                hint="Pelo dia do pagamento"
            />
            <StatTile
                label="Resultado do mês"
                :value="formatCents(overview.resultCents)"
                :icon="Scale"
                :hint="
                    overview.resultCents >= 0
                        ? 'Vendas + receitas − pagamentos'
                        : 'Saiu mais do que entrou'
                "
            />
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Ainda vence neste mês</h2>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <Link
                        :href="payables()"
                        class="rounded-xl border p-3 transition hover:border-primary/50"
                    >
                        <span class="text-sm text-muted-foreground"
                            >A pagar</span
                        >
                        <span class="block text-lg font-semibold">{{
                            formatCents(overview.toPayCents)
                        }}</span>
                    </Link>
                    <Link
                        :href="receivables()"
                        class="rounded-xl border p-3 transition hover:border-primary/50"
                    >
                        <span class="text-sm text-muted-foreground"
                            >A receber</span
                        >
                        <span class="block text-lg font-semibold">{{
                            formatCents(overview.toReceiveCents)
                        }}</span>
                    </Link>
                </div>

                <div class="flex items-center justify-between gap-2 pt-2">
                    <h2 class="font-semibold">Despesas por categoria</h2>
                    <Link
                        :href="categoriesIndex()"
                        class="flex items-center gap-1 text-xs text-primary hover:underline"
                        ><Tags class="size-3.5" /> Categorias</Link
                    >
                </div>
                <p
                    v-if="overview.expensesByCategory.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nenhuma conta paga neste mês.
                </p>
                <ul v-else class="flex flex-col gap-2.5">
                    <li
                        v-for="row in overview.expensesByCategory"
                        :key="row.name"
                        class="grid gap-1"
                    >
                        <div class="flex justify-between gap-2 text-sm">
                            <span class="truncate">{{ row.name }}</span>
                            <span class="font-medium tabular-nums">{{
                                formatCents(row.cents)
                            }}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-muted">
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{
                                    width: `${Math.max(2, (row.cents / maxCategory) * 100)}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
            </section>

            <section
                class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <h2 class="font-semibold">Próximos vencimentos</h2>
                    <Link
                        :href="suppliersIndex()"
                        class="flex items-center gap-1 text-xs text-primary hover:underline"
                        ><Truck class="size-3.5" /> Fornecedores</Link
                    >
                </div>
                <p class="-mt-2 text-xs text-muted-foreground">
                    Atrasadas e os próximos 7 dias.
                </p>
                <p
                    v-if="upcoming.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    Nada vencendo. Tudo em dia!
                </p>
                <ul v-else class="flex flex-col divide-y">
                    <li
                        v-for="row in upcoming"
                        :key="row.id"
                        class="flex items-center gap-3 py-2.5"
                    >
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-lg"
                            :class="
                                row.type === 'payable'
                                    ? 'bg-muted text-foreground'
                                    : 'bg-primary/10 text-primary'
                            "
                        >
                            <ArrowUpRight
                                v-if="row.type === 'payable'"
                                class="size-4"
                                aria-label="A pagar"
                            />
                            <ArrowDownLeft
                                v-else
                                class="size-4"
                                aria-label="A receber"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="show(row.titleId)"
                                class="block truncate text-sm font-medium hover:underline"
                            >
                                {{ row.description }}
                                <template v-if="row.count > 1">
                                    ({{ row.number }}/{{ row.count }})</template
                                >
                            </Link>
                            <span
                                class="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground"
                            >
                                {{ formatDate(row.dueOn) }}
                                <InstallmentStatusBadge
                                    v-if="row.status === 'overdue'"
                                    :status="row.status"
                                    :label="row.statusLabel"
                                />
                            </span>
                        </div>
                        <span class="text-sm font-medium tabular-nums">{{
                            formatCents(row.amountCents)
                        }}</span>
                        <Button
                            size="sm"
                            variant="outline"
                            @click="settle(row)"
                            >{{ paidWord(row.type).verb }}</Button
                        >
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <SettleDialog
        v-model:open="settleOpen"
        :installment="settling"
        :payment-methods="paymentMethods"
        :today="today"
        :cash-open="cashOpen"
    />
</template>
