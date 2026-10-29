<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import ShareList from '@/components/charts/ShareList.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { exportMethod as exportRoute, index } from '@/routes/reports';

type Totals = {
    revenueCents: number;
    salesCount: number;
    averageTicketCents: number;
    cancelledCount: number;
    cancelledCents: number;
    discountCents: number;
    serviceFeeCents: number;
    deliveryFeeCents: number;
};

const props = defineProps<{
    period: {
        preset: string;
        from: string;
        to: string;
        label: string;
        previousLabel: string;
    };
    report: {
        totals: Totals;
        previous: Totals;
        daily: { date: string; revenueCents: number; salesCount: number }[];
        hourly: { hour: number; salesCount: number; revenueCents: number }[];
        channels: {
            key: string;
            label: string;
            salesCount: number;
            revenueCents: number;
        }[];
        payments: { method: string; label: string; cents: number }[];
        topProducts: { name: string; quantity: number; revenueCents: number }[];
        topCustomers: {
            name: string;
            phone: string | null;
            ordersCount: number;
            revenueCents: number;
        }[];
        coupons: { code: string; uses: number; discountCents: number }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Relatórios', href: index() }],
    },
});

const presets = [
    { value: 'today', label: 'Hoje' },
    { value: 'yesterday', label: 'Ontem' },
    { value: '7d', label: '7 dias' },
    { value: '30d', label: '30 dias' },
    { value: 'month', label: 'Este mês' },
    { value: 'last_month', label: 'Mês passado' },
    { value: 'custom', label: 'Escolher datas' },
];

const customFrom = ref(props.period.from);
const customTo = ref(props.period.to);
const showCustom = ref(props.period.preset === 'custom');

function load(query: Record<string, string>): void {
    router.get(index.url(), query, {
        preserveScroll: true,
        preserveState: true,
    });
}

function pick(preset: string): void {
    if (preset === 'custom') {
        showCustom.value = true;

        return;
    }

    showCustom.value = false;
    load({ period: preset });
}

function applyCustom(): void {
    load({ period: 'custom', from: customFrom.value, to: customTo.value });
}

const exportUrl = computed(() =>
    exportRoute.url({
        query: {
            period: props.period.preset,
            from: props.period.from,
            to: props.period.to,
        },
    }),
);

function delta(
    current: number,
    previous: number,
): { text: string; positive: boolean } | null {
    if (previous === 0) {
        return null;
    }

    const change = Math.round(((current - previous) / previous) * 100);

    return {
        text: `${change > 0 ? '+' : ''}${change}%`,
        positive: change >= 0,
    };
}

const cards = computed(() => {
    const { totals, previous } = props.report;

    return [
        {
            label: 'Faturamento',
            value: formatCents(totals.revenueCents),
            delta: delta(totals.revenueCents, previous.revenueCents),
        },
        {
            label: 'Vendas',
            value: String(totals.salesCount),
            delta: delta(totals.salesCount, previous.salesCount),
        },
        {
            label: 'Ticket médio',
            value: formatCents(totals.averageTicketCents),
            delta: delta(
                totals.averageTicketCents,
                previous.averageTicketCents,
            ),
        },
        {
            label: 'Cancelados',
            value: String(totals.cancelledCount),
            hint:
                totals.cancelledCents > 0
                    ? formatCents(totals.cancelledCents)
                    : null,
            delta: null,
        },
    ];
});

const dayFormat = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'UTC',
});
const weekday = new Intl.DateTimeFormat('pt-BR', {
    weekday: 'short',
    timeZone: 'UTC',
});

const dailyBars = computed(() =>
    props.report.daily.map((day) => {
        const date = new Date(`${day.date}T00:00:00Z`);

        return {
            label:
                props.report.daily.length <= 7
                    ? weekday.format(date)
                    : dayFormat.format(date),
            value: day.revenueCents,
            title: `${dayFormat.format(date)}: ${formatCents(day.revenueCents)} em ${day.salesCount} venda(s)`,
        };
    }),
);

const hourlyBars = computed(() =>
    props.report.hourly.map((hour) => ({
        label: `${hour.hour}h`,
        value: hour.salesCount,
        title: `${hour.hour}h: ${hour.salesCount} venda(s), ${formatCents(hour.revenueCents)}`,
    })),
);

const fulfillmentRows = computed(() =>
    props.report.channels
        .filter((row) => !row.key.startsWith('origin_'))
        .map((row) => ({
            key: row.key,
            label: row.label,
            value: row.revenueCents,
            display: formatCents(row.revenueCents),
            hint: `${row.salesCount} venda(s)`,
        })),
);

const originRows = computed(() =>
    props.report.channels
        .filter((row) => row.key.startsWith('origin_'))
        .map((row) => ({
            key: row.key,
            label: row.label,
            value: row.revenueCents,
            display: formatCents(row.revenueCents),
            hint: `${row.salesCount} venda(s)`,
        })),
);

const paymentRows = computed(() =>
    props.report.payments
        .filter((row) => row.cents !== 0)
        .map((row) => ({
            key: row.method,
            label: row.label,
            value: row.cents,
            display: formatCents(row.cents),
        })),
);

function phoneLabel(digits: string | null): string {
    if (!digits) {
        return '';
    }

    const local = digits.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : digits;
}
</script>

<template>
    <Head title="Relatórios" />

    <div class="flex max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading title="Relatórios" :description="period.label" />
            <Button as-child variant="outline" class="ml-auto">
                <a :href="exportUrl">
                    <Download />
                    Exportar pedidos (Excel)
                </a>
            </Button>
        </div>

        <div class="flex flex-col gap-2">
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="preset in presets"
                    :key="preset.value"
                    size="sm"
                    :variant="
                        (showCustom ? 'custom' : period.preset) === preset.value
                            ? 'default'
                            : 'outline'
                    "
                    @click="pick(preset.value)"
                >
                    {{ preset.label }}
                </Button>
            </div>
            <form
                v-if="showCustom"
                class="flex flex-wrap items-center gap-2"
                @submit.prevent="applyCustom"
            >
                <Input
                    v-model="customFrom"
                    type="date"
                    class="w-40"
                    aria-label="Data inicial"
                />
                <span class="text-sm text-muted-foreground">até</span>
                <Input
                    v-model="customTo"
                    type="date"
                    class="w-40"
                    aria-label="Data final"
                />
                <Button type="submit" size="sm">Ver</Button>
            </form>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                v-for="card in cards"
                :key="card.label"
                class="rounded-xl border p-4"
            >
                <p class="text-sm text-muted-foreground">{{ card.label }}</p>
                <p class="text-2xl font-semibold">{{ card.value }}</p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="card.delta">
                        <span
                            :class="
                                card.delta.positive
                                    ? 'text-emerald-600'
                                    : 'text-destructive'
                            "
                            >{{ card.delta.text }}</span
                        >
                        vs. {{ period.previousLabel }}
                    </template>
                    <template v-else-if="card.hint">{{ card.hint }}</template>
                    <template v-else>&nbsp;</template>
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 font-medium">Faturamento por dia</h2>
                <BarChart
                    :items="dailyBars"
                    :label-every="dailyBars.length > 14 ? 5 : 1"
                />
            </section>
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 font-medium">Horários de pico</h2>
                <BarChart :items="hourlyBars" :label-every="3" />
            </section>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 font-medium">Por tipo de atendimento</h2>
                <ShareList :rows="fulfillmentRows" />
            </section>
            <section class="rounded-xl border p-4">
                <h2 class="mb-3 font-medium">Por origem (sem mesas)</h2>
                <ShareList :rows="originRows" />
            </section>
            <section class="rounded-xl border p-4">
                <h2 class="mb-1 font-medium">Recebido no caixa</h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    Pagamentos registrados no caixa no período, já descontados
                    os estornos.
                </p>
                <p
                    v-if="paymentRows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nada recebido no caixa.
                </p>
                <ShareList v-else :rows="paymentRows" />
            </section>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border text-sm">
                <h2 class="border-b p-4 font-medium">Produtos mais vendidos</h2>
                <p
                    v-if="report.topProducts.length === 0"
                    class="p-4 text-muted-foreground"
                >
                    Nenhuma venda no período.
                </p>
                <ol v-else class="divide-y">
                    <li
                        v-for="(product, position) in report.topProducts"
                        :key="product.name"
                        class="flex items-center gap-3 px-4 py-2"
                    >
                        <span class="w-5 text-muted-foreground">{{
                            position + 1
                        }}</span>
                        <span class="flex-1">{{ product.name }}</span>
                        <span class="text-muted-foreground"
                            >{{ product.quantity }} un.</span
                        >
                        <span class="w-24 text-right font-medium">{{
                            formatCents(product.revenueCents)
                        }}</span>
                    </li>
                </ol>
            </section>
            <section class="rounded-xl border text-sm">
                <h2 class="border-b p-4 font-medium">Melhores clientes</h2>
                <p
                    v-if="report.topCustomers.length === 0"
                    class="p-4 text-muted-foreground"
                >
                    Nenhum cliente identificado no período.
                </p>
                <ol v-else class="divide-y">
                    <li
                        v-for="customer in report.topCustomers"
                        :key="`${customer.name}-${customer.phone}`"
                        class="flex items-center gap-3 px-4 py-2"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate">{{
                                customer.name
                            }}</span>
                            <a
                                v-if="customer.phone"
                                :href="`https://wa.me/${customer.phone}`"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-muted-foreground underline-offset-4 hover:underline"
                                >{{ phoneLabel(customer.phone) }}</a
                            >
                        </span>
                        <span class="text-muted-foreground"
                            >{{ customer.ordersCount }} pedido(s)</span
                        >
                        <span class="w-24 text-right font-medium">{{
                            formatCents(customer.revenueCents)
                        }}</span>
                    </li>
                </ol>
            </section>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <section class="rounded-xl border p-4 text-sm">
                <h2 class="mb-3 font-medium">Descontos, taxas e cupons</h2>
                <dl class="grid gap-1">
                    <div class="flex justify-between">
                        <dt>Descontos concedidos</dt>
                        <dd>{{ formatCents(report.totals.discountCents) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Taxas de entrega</dt>
                        <dd>
                            {{ formatCents(report.totals.deliveryFeeCents) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Taxa de serviço (mesas)</dt>
                        <dd>
                            {{ formatCents(report.totals.serviceFeeCents) }}
                        </dd>
                    </div>
                </dl>
                <ul
                    v-if="report.coupons.length > 0"
                    class="mt-3 flex flex-col gap-1 border-t pt-3"
                >
                    <li
                        v-for="coupon in report.coupons"
                        :key="coupon.code"
                        class="flex justify-between"
                    >
                        <span class="font-mono">{{ coupon.code }}</span>
                        <span class="text-muted-foreground"
                            >{{ coupon.uses }} uso(s) ·
                            {{ formatCents(coupon.discountCents) }}</span
                        >
                    </li>
                </ul>
            </section>
            <section
                class="rounded-xl border p-4 text-sm text-muted-foreground"
            >
                <h2 class="mb-2 font-medium text-foreground">
                    Como os números são calculados
                </h2>
                <p>
                    Conta como venda cada pedido concluído (cardápio, PDV ou
                    entrega) e cada conta de mesa fechada. Os pedidos lançados
                    na mesa entram pelo valor da conta, já com taxa de serviço e
                    desconto. Pedidos cancelados ficam de fora do faturamento.
                    Os dias seguem o fuso horário da loja.
                </p>
            </section>
        </div>
    </div>
</template>
