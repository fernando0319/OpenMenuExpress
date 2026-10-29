<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import {
    AlertCircle,
    Armchair,
    ArrowRight,
    Circle,
    CircleCheck,
    Clock,
    Power,
    ReceiptText,
    ShoppingBag,
    ShoppingCart,
    TrendingUp,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import StatTile from '@/components/dashboard/StatTile.vue';
import FreeUsageMeter from '@/components/FreeUsageMeter.vue';
import { Badge } from '@/components/ui/badge';
import StoreStatusController from '@/actions/App/Http/Controllers/StoreStatusController';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes';
import { show as billingShow } from '@/routes/billing';
import { payables, receivables } from '@/routes/finance';
import { index as categoriesIndex } from '@/routes/catalog/categories';
import { index as productsIndex } from '@/routes/catalog/products';
import { index as ordersIndex, show as orderShow } from '@/routes/orders';
import { show as posShow } from '@/routes/pos';
import { index as reportsIndex } from '@/routes/reports';
import { edit as appearanceEdit } from '@/routes/store-settings/appearance';
import { edit as hoursEdit } from '@/routes/store-settings/hours';
import { edit as messagesEdit } from '@/routes/store-settings/messages';
import { index as tablesIndex, show as tableShow } from '@/routes/tables';

type Totals = {
    revenueCents: number;
    salesCount: number;
    averageTicketCents: number;
    cancelledCount: number;
};

const props = defineProps<{
    status: { isOpen: boolean; isPaused: boolean };
    today: { current: Totals; previous: Totals } | null;
    week: { date: string; revenueCents: number; salesCount: number }[] | null;
    topProducts:
        | { name: string; quantity: number; revenueCents: number }[]
        | null;
    topCustomers:
        | {
              name: string;
              phone: string | null;
              ordersCount: number;
              revenueCents: number;
          }[]
        | null;
    orders: {
        count: number;
        pending: number;
        latest: {
            id: number;
            number: number;
            customerName: string;
            fulfillment: string;
            status: string;
            statusLabel: string;
            totalCents: number;
            placedAt: string;
            scheduledFor: string | null;
        }[];
    } | null;
    tables: {
        total: number;
        busy: number;
        closing: number;
        occupied: {
            id: number;
            name: string;
            status: string | null;
            people: number | null;
            openedAt: string | null;
            consumedCents: number;
        }[];
    } | null;
    setup: { key: string; done: boolean }[] | null;
    finance: {
        overdue: { count: number; cents: number };
        today: { count: number; cents: number };
        overdueReceivable: { count: number; cents: number };
    } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Início', href: dashboard() }],
    },
});

// Orders and tables move all the time.
usePoll(15_000, { only: ['orders', 'tables', 'today', 'status'] });

const page = usePage();
const currentStore = computed(() => page.props.currentStore);
const firstName = computed(
    () => (page.props.auth.user?.name ?? '').split(' ')[0],
);

const now = ref(Date.now());
const ticker = setInterval(() => (now.value = Date.now()), 30_000);
onBeforeUnmount(() => clearInterval(ticker));

function minutesSince(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.floor((now.value - new Date(iso).getTime()) / 60_000),
    );

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, '0')}`;
}

const compact = new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    notation: 'compact',
    maximumFractionDigits: 1,
});
const weekday = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'short',
    timeZone: 'UTC',
});
const dayMonth = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'UTC',
});

const weekBars = computed(() =>
    (props.week ?? []).map((day) => {
        const date = new Date(`${day.date}T00:00:00Z`);

        return {
            label: weekday.format(date).replace('.', ''),
            value: day.revenueCents,
            title: `${dayMonth.format(date)}: ${formatCents(day.revenueCents)} em ${day.salesCount} venda(s)`,
        };
    }),
);

function compactMoney(cents: number): string {
    return compact.format(cents / 100);
}

const weekTotal = computed(() =>
    (props.week ?? []).reduce((sum, day) => sum + day.revenueCents, 0),
);
const topProductMax = computed(() =>
    Math.max(
        1,
        ...(props.topProducts ?? []).map((product) => product.quantity),
    ),
);

const setupLabels: Record<
    string,
    { title: string; href: ReturnType<typeof dashboard> }
> = {
    categories: { title: 'Criar as categorias', href: categoriesIndex() },
    products: { title: 'Cadastrar os produtos', href: productsIndex() },
    appearance: { title: 'Enviar a logo da loja', href: appearanceEdit() },
    hours: { title: 'Definir os horários', href: hoursEdit() },
    whatsapp: { title: 'Conectar o WhatsApp', href: messagesEdit() },
};
const setupDone = computed(
    () => (props.setup ?? []).filter((step) => step.done).length,
);
const showSetup = computed(
    () => props.setup !== null && setupDone.value < props.setup.length,
);

const togglingStatus = ref(false);

function toggleOpen(): void {
    const open = props.status.isPaused;

    if (
        !open &&
        !window.confirm(
            'Fechar a loja? O cardápio para de aceitar pedidos até você abrir de novo.',
        )
    ) {
        return;
    }

    router.put(
        StoreStatusController.url(),
        { open },
        {
            preserveScroll: true,
            onStart: () => (togglingStatus.value = true),
            onFinish: () => (togglingStatus.value = false),
        },
    );
}

const statusText = computed(() => {
    if (props.status.isPaused) {
        return 'Fechada';
    }

    return props.status.isOpen ? 'Aberta agora' : 'Fechada agora';
});

function phoneLabel(digits: string | null): string {
    if (!digits) {
        return '';
    }

    const local = digits.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : digits;
}

function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}
</script>

<template>
    <Head title="Início" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex flex-col gap-1">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Olá{{ firstName ? `, ${firstName}` : '' }}!
                </h1>
                <p
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    {{ currentStore?.name }}
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="
                            status.isOpen && !status.isPaused
                                ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                        "
                    >
                        <span class="size-1.5 rounded-full bg-current" />
                        {{ statusText }}
                    </span>
                </p>
                <p
                    v-if="!status.isPaused && !status.isOpen"
                    class="text-xs text-muted-foreground"
                >
                    Fora do horário de funcionamento: o cardápio volta a aceitar
                    pedidos no próximo horário.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="currentStore?.canOperatePos"
                    :variant="status.isPaused ? 'default' : 'outline'"
                    :class="
                        status.isPaused
                            ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                            : 'border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-900 dark:text-rose-400 dark:hover:bg-rose-950'
                    "
                    :disabled="togglingStatus"
                    @click="toggleOpen"
                >
                    <Power />
                    {{ status.isPaused ? 'Abrir loja' : 'Fechar loja' }}
                </Button>
                <Button
                    v-if="currentStore?.canViewOrders"
                    as-child
                    variant="outline"
                >
                    <Link :href="ordersIndex()"><ReceiptText /> Pedidos</Link>
                </Button>
                <Button v-if="currentStore?.canOperatePos" as-child>
                    <Link :href="posShow()"><ShoppingCart /> Abrir PDV</Link>
                </Button>
            </div>
        </div>

        <section
            v-if="finance"
            class="flex flex-col gap-2 rounded-2xl border border-amber-400/50 bg-amber-500/10 p-4 text-sm sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-6"
            aria-label="Contas"
        >
            <span class="flex items-center gap-2 font-semibold">
                <AlertCircle class="size-4" aria-hidden="true" /> Financeiro
            </span>
            <Link
                v-if="finance.overdue.count"
                :href="payables({ query: { status: 'overdue' } })"
                class="hover:underline"
                >{{ finance.overdue.count }} conta(s) a pagar vencida(s) ·
                {{ formatCents(finance.overdue.cents) }}</Link
            >
            <Link
                v-if="finance.today.count"
                :href="payables()"
                class="hover:underline"
                >{{ finance.today.count }} vence(m) hoje ·
                {{ formatCents(finance.today.cents) }}</Link
            >
            <Link
                v-if="finance.overdueReceivable.count"
                :href="receivables({ query: { status: 'overdue' } })"
                class="hover:underline"
                >{{ finance.overdueReceivable.count }} a receber atrasada(s) ·
                {{ formatCents(finance.overdueReceivable.cents) }}</Link
            >
        </section>

        <section
            v-if="currentStore?.subscription.free"
            class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl border bg-card p-5 shadow-xs"
        >
            <div class="min-w-48">
                <h2 class="font-semibold">Plano gratuito</h2>
                <p class="text-sm text-muted-foreground">Vendas do mês</p>
            </div>
            <FreeUsageMeter
                class="min-w-60 flex-1"
                :limit-cents="currentStore.subscription.free.limitCents"
                :sales-cents="currentStore.subscription.free.salesCents"
            />
            <Button
                v-if="currentStore.canManageStore"
                variant="outline"
                as-child
            >
                <Link :href="billingShow()">Ver planos</Link>
            </Button>
        </section>

        <section
            v-if="showSetup && setup"
            class="rounded-2xl border bg-card p-5 shadow-xs"
        >
            <div class="mb-3 flex items-center justify-between gap-3">
                <div>
                    <h2 class="font-semibold">Primeiros passos</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ setupDone }} de {{ setup.length }} concluídos
                    </p>
                </div>
                <div
                    class="h-2 w-32 overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="`${setupDone} de ${setup.length} passos concluídos`"
                >
                    <div
                        class="h-full rounded-full bg-primary"
                        :style="{
                            width: `${(setupDone / setup.length) * 100}%`,
                        }"
                    />
                </div>
            </div>
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                <li v-for="step in setup" :key="step.key">
                    <Link
                        :href="setupLabels[step.key].href"
                        class="flex items-center gap-2 rounded-xl border px-3 py-2.5 text-sm transition-colors hover:bg-muted"
                        :class="{
                            'text-muted-foreground line-through': step.done,
                        }"
                    >
                        <CircleCheck
                            v-if="step.done"
                            class="size-4 shrink-0 text-primary"
                        />
                        <Circle
                            v-else
                            class="size-4 shrink-0 text-muted-foreground"
                        />
                        {{ setupLabels[step.key].title }}
                    </Link>
                </li>
            </ul>
        </section>

        <section class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <template v-if="today">
                <StatTile
                    label="Vendas hoje"
                    :value="formatCents(today.current.revenueCents)"
                    :icon="Wallet"
                    :current="today.current.revenueCents"
                    :previous="today.previous.revenueCents"
                    compared-to="vs. ontem"
                    hint="Sem vendas ontem para comparar"
                />
                <StatTile
                    label="Pedidos hoje"
                    :value="String(today.current.salesCount)"
                    :icon="ShoppingBag"
                    :current="today.current.salesCount"
                    :previous="today.previous.salesCount"
                    compared-to="vs. ontem"
                    :hint="
                        today.current.cancelledCount > 0
                            ? `${today.current.cancelledCount} cancelado(s)`
                            : undefined
                    "
                />
                <StatTile
                    label="Ticket médio"
                    :value="formatCents(today.current.averageTicketCents)"
                    :icon="TrendingUp"
                    :current="today.current.averageTicketCents"
                    :previous="today.previous.averageTicketCents"
                    compared-to="vs. ontem"
                />
            </template>
            <StatTile
                v-if="orders"
                label="Em andamento"
                :value="String(orders.count)"
                :icon="Clock"
                :hint="
                    orders.pending > 0
                        ? `${orders.pending} aguardando aceite`
                        : 'Nenhum aguardando aceite'
                "
            />
            <StatTile
                v-if="tables && !today"
                label="Mesas ocupadas"
                :value="`${tables.busy} de ${tables.total}`"
                :icon="Armchair"
                :hint="
                    tables.closing > 0
                        ? `${tables.closing} pediram a conta`
                        : undefined
                "
            />
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <section
                v-if="week"
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-xs lg:col-span-2"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Vendas nos últimos 7 dias</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ formatCents(weekTotal) }} no total
                        </p>
                    </div>
                    <Button as-child variant="ghost" size="sm">
                        <Link :href="reportsIndex()"
                            >Relatórios <ArrowRight
                        /></Link>
                    </Button>
                </div>
                <BarChart
                    :items="weekBars"
                    height="12rem"
                    :format="compactMoney"
                />
            </section>

            <section
                v-if="orders"
                class="flex flex-col rounded-2xl border bg-card shadow-xs"
                :class="{ 'lg:col-span-3': !week }"
            >
                <div class="flex items-center justify-between gap-3 p-5 pb-3">
                    <h2 class="font-semibold">Pedidos em andamento</h2>
                    <Link
                        :href="ordersIndex()"
                        class="text-sm text-primary underline-offset-4 hover:underline"
                        >Ver todos</Link
                    >
                </div>
                <p
                    v-if="orders.latest.length === 0"
                    class="px-5 pb-5 text-sm text-muted-foreground"
                >
                    Nenhum pedido em andamento agora.
                </p>
                <ul v-else class="flex flex-col divide-y">
                    <li v-for="order in orders.latest" :key="order.id">
                        <Link
                            :href="orderShow(order.id)"
                            class="flex items-center gap-3 px-5 py-3 transition-colors hover:bg-muted/60"
                        >
                            <span
                                class="grid size-10 shrink-0 place-items-center rounded-xl bg-muted text-sm font-semibold"
                                >{{ order.number }}</span
                            >
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ order.customerName }}</span
                                >
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ order.fulfillment }} ·
                                    {{
                                        order.scheduledFor
                                            ? `agendado: ${order.scheduledFor}`
                                            : `há ${minutesSince(order.placedAt)}`
                                    }}
                                </span>
                            </span>
                            <span class="flex flex-col items-end gap-1">
                                <span class="text-sm font-medium">{{
                                    formatCents(order.totalCents)
                                }}</span>
                                <Badge
                                    :variant="
                                        order.status === 'pending'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                    class="text-[10px]"
                                    >{{ order.statusLabel }}</Badge
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <section
                v-if="topProducts"
                class="rounded-2xl border bg-card p-5 shadow-xs"
            >
                <h2 class="font-semibold">Mais vendidos</h2>
                <p class="mb-4 text-sm text-muted-foreground">Últimos 7 dias</p>
                <p
                    v-if="topProducts.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nenhuma venda no período.
                </p>
                <ol v-else class="flex flex-col gap-3">
                    <li
                        v-for="(product, position) in topProducts"
                        :key="product.name"
                        class="flex flex-col gap-1.5"
                    >
                        <div
                            class="flex items-baseline justify-between gap-3 text-sm"
                        >
                            <span class="truncate"
                                ><span class="mr-2 text-muted-foreground">{{
                                    position + 1
                                }}</span
                                >{{ product.name }}</span
                            >
                            <span class="shrink-0 text-muted-foreground"
                                >{{ product.quantity }} un.</span
                            >
                        </div>
                        <div
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-primary"
                                :style="{
                                    width: `${(product.quantity / topProductMax) * 100}%`,
                                }"
                            />
                        </div>
                    </li>
                </ol>
            </section>

            <section
                v-if="topCustomers"
                class="rounded-2xl border bg-card p-5 shadow-xs"
            >
                <h2 class="font-semibold">Melhores clientes</h2>
                <p class="mb-4 text-sm text-muted-foreground">
                    Últimos 30 dias
                </p>
                <p
                    v-if="topCustomers.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nenhum cliente identificado no período.
                </p>
                <ul v-else class="flex flex-col gap-3">
                    <li
                        v-for="customer in topCustomers"
                        :key="`${customer.name}-${customer.phone}`"
                        class="flex items-center gap-3"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
                            >{{ initials(customer.name) }}</span
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium">{{
                                customer.name
                            }}</span>
                            <a
                                v-if="customer.phone"
                                :href="`https://wa.me/${customer.phone}`"
                                target="_blank"
                                rel="noopener"
                                class="block truncate text-xs text-muted-foreground underline-offset-4 hover:underline"
                                >{{ phoneLabel(customer.phone) }}</a
                            >
                        </span>
                        <span class="text-right">
                            <span class="block text-sm font-medium">{{
                                formatCents(customer.revenueCents)
                            }}</span>
                            <span class="block text-xs text-muted-foreground"
                                >{{ customer.ordersCount }} pedido(s)</span
                            >
                        </span>
                    </li>
                </ul>
            </section>

            <section
                v-if="tables"
                class="rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Mesas</h2>
                        <p class="mb-4 text-sm text-muted-foreground">
                            {{ tables.busy }} ocupada(s) de {{ tables.total }}
                            <template v-if="tables.closing > 0">
                                · {{ tables.closing }} pediram a conta</template
                            >
                        </p>
                    </div>
                    <Link
                        :href="tablesIndex()"
                        class="text-sm text-primary underline-offset-4 hover:underline"
                        >Abrir</Link
                    >
                </div>
                <div
                    class="mb-4 flex h-2 gap-0.5 overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="`${tables.busy} de ${tables.total} mesas ocupadas`"
                >
                    <div
                        class="h-full bg-primary"
                        :style="{
                            width: `${((tables.busy - tables.closing) / tables.total) * 100}%`,
                        }"
                    />
                    <div
                        class="h-full bg-amber-500"
                        :style="{
                            width: `${(tables.closing / tables.total) * 100}%`,
                        }"
                    />
                </div>
                <p
                    v-if="tables.occupied.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Todas as mesas estão livres.
                </p>
                <ul v-else class="flex flex-col divide-y">
                    <li
                        v-for="table in tables.occupied.slice(0, 6)"
                        :key="table.id"
                    >
                        <Link
                            :href="tableShow(table.id)"
                            class="flex items-center justify-between gap-3 py-2 text-sm"
                        >
                            <span class="flex items-center gap-2">
                                <span
                                    class="size-2 rounded-full"
                                    :class="
                                        table.status === 'closing'
                                            ? 'bg-amber-500'
                                            : 'bg-primary'
                                    "
                                />
                                {{ table.name }}
                                <span
                                    v-if="table.people"
                                    class="flex items-center gap-0.5 text-xs text-muted-foreground"
                                    ><Users class="size-3" />
                                    {{ table.people }}</span
                                >
                            </span>
                            <span class="text-right">
                                <span class="block font-medium">{{
                                    formatCents(table.consumedCents)
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    {{
                                        table.status === 'closing'
                                            ? 'conta pedida'
                                            : minutesSince(table.openedAt)
                                    }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
