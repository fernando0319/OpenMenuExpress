<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    AlertCircle,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    Eye,
    Plus,
    Search,
    Undo2,
    Zap,
} from '@lucide/vue';
import { computed, reactive, ref, watch, watchEffect } from 'vue';
import InstallmentController from '@/actions/App/Http/Controllers/Finance/InstallmentController';
import InstallmentStatusBadge from '@/components/finance/InstallmentStatusBadge.vue';
import SettleDialog from '@/components/finance/SettleDialog.vue';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatDate, paidWord } from '@/lib/finance';
import { formatCents } from '@/lib/money';
import { overview, payables, receivables } from '@/routes/finance';
import { index as suppliersIndex } from '@/routes/finance/suppliers';
import { create, show } from '@/routes/finance/titles';
import type { Paginated } from '@/types';
import type {
    CountAndCents,
    FinanceKind,
    InstallmentRow,
    NamedOption,
    Option,
} from '@/types/finance';

const props = defineProps<{
    type: FinanceKind;
    installments: Paginated<InstallmentRow>;
    totalCents: number;
    filters: {
        status: string;
        from: string;
        to: string;
        q: string;
        supplier: string;
        category: string;
    };
    summary: {
        overdue: CountAndCents;
        today: CountAndCents;
        next7: CountAndCents;
        paidThisMonthCents: number;
    };
    suppliers: NamedOption[];
    categories: NamedOption[];
    paymentMethods: Option[];
    today: string;
    cashOpen: boolean;
}>();

const isPayable = computed(() => props.type === 'payable');
const title = computed(() =>
    isPayable.value ? 'Contas a pagar' : 'Contas a receber',
);
const words = computed(() => paidWord(props.type));
const listRoute = computed(() => (isPayable.value ? payables : receivables));

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Financeiro', href: overview() },
            { title: title.value, href: listRoute.value() },
        ],
    });
});

const statuses = computed(() => [
    { value: 'open', label: 'Em aberto' },
    { value: 'overdue', label: 'Vencidas' },
    { value: 'paid', label: isPayable.value ? 'Pagas' : 'Recebidas' },
    { value: 'canceled', label: 'Canceladas' },
    { value: 'all', label: 'Todas' },
]);

const filters = reactive({ ...props.filters });

let searchTimer: ReturnType<typeof setTimeout> | undefined;

function apply(): void {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== '' && !(key === 'status' && value === 'open')) {
            query[key] = value;
        }
    }

    router.get(
        listRoute.value.url({ query }),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

watch(
    () => [
        filters.status,
        filters.from,
        filters.to,
        filters.supplier,
        filters.category,
    ],
    apply,
);
watch(
    () => filters.q,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(apply, 350);
    },
);

function showStatus(status: string): void {
    filters.status = status;
}

const settleOpen = ref(false);
const settling = ref<InstallmentRow | null>(null);

function settle(row: InstallmentRow): void {
    settling.value = row;
    settleOpen.value = true;
}

function undo(row: InstallmentRow): void {
    if (
        !confirm(
            `Desfazer a baixa de "${row.description}"? A parcela volta a ficar em aberto.`,
        )
    ) {
        return;
    }

    router.delete(InstallmentController.undo.url(row.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="title"
                :description="
                    isPayable
                        ? 'Compras, boletos e despesas da loja, com as parcelas e a baixa de cada uma.'
                        : 'Valores que a loja tem para receber: vendas a prazo, convênios, encomendas.'
                "
            />
            <div class="flex flex-wrap gap-2">
                <Button v-if="isPayable" variant="outline" as-child>
                    <Link :href="suppliersIndex()">Fornecedores</Link>
                </Button>
                <Button as-child>
                    <Link :href="create({ query: { type } })">
                        <Plus />
                        {{
                            isPayable
                                ? 'Nova conta a pagar'
                                : 'Nova conta a receber'
                        }}
                    </Link>
                </Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <button
                type="button"
                class="flex flex-col gap-1 rounded-2xl border bg-card p-4 text-left shadow-xs transition hover:border-destructive/50"
                :class="{
                    'border-destructive/40 bg-destructive/5':
                        summary.overdue.count > 0,
                }"
                @click="showStatus('overdue')"
            >
                <span
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <AlertCircle
                        class="size-4"
                        :class="{ 'text-destructive': summary.overdue.count }"
                    />
                    Vencidas
                </span>
                <span class="text-xl font-semibold">{{
                    formatCents(summary.overdue.cents)
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >{{ summary.overdue.count }} parcela(s)</span
                >
            </button>
            <div
                class="flex flex-col gap-1 rounded-2xl border bg-card p-4 shadow-xs"
            >
                <span
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <CalendarClock class="size-4" /> Vencem hoje
                </span>
                <span class="text-xl font-semibold">{{
                    formatCents(summary.today.cents)
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >{{ summary.today.count }} parcela(s)</span
                >
            </div>
            <div
                class="flex flex-col gap-1 rounded-2xl border bg-card p-4 shadow-xs"
            >
                <span
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <CalendarDays class="size-4" /> Próximos 7 dias
                </span>
                <span class="text-xl font-semibold">{{
                    formatCents(summary.next7.cents)
                }}</span>
                <span class="text-xs text-muted-foreground"
                    >{{ summary.next7.count }} parcela(s)</span
                >
            </div>
            <button
                type="button"
                class="flex flex-col gap-1 rounded-2xl border bg-card p-4 text-left shadow-xs transition hover:border-primary/50"
                @click="showStatus('paid')"
            >
                <span
                    class="flex items-center gap-1.5 text-sm text-muted-foreground"
                >
                    <CheckCircle2 class="size-4" />
                    {{ isPayable ? 'Pago' : 'Recebido' }} neste mês
                </span>
                <span class="text-xl font-semibold">{{
                    formatCents(summary.paidThisMonthCents)
                }}</span>
                <Link
                    :href="overview()"
                    class="text-xs text-primary hover:underline"
                    @click.stop
                    >Ver o resumo do mês</Link
                >
            </button>
        </div>

        <div class="flex flex-col gap-3">
            <div
                class="flex flex-wrap gap-1 rounded-xl bg-muted p-1"
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
                            : 'text-muted-foreground hover:text-foreground'
                    "
                    @click="filters.status = option.value"
                >
                    {{ option.label }}
                </button>
            </div>

            <div
                class="grid gap-2 sm:grid-cols-2 lg:grid-cols-[1fr_auto_auto_12rem_12rem]"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="filters.q"
                        class="pl-9"
                        :placeholder="
                            isPayable
                                ? 'Buscar por descrição, fornecedor ou nº do documento'
                                : 'Buscar por descrição, cliente ou nº do documento'
                        "
                        aria-label="Buscar"
                    />
                </div>
                <Input
                    v-model="filters.from"
                    type="date"
                    :aria-label="
                        filters.status === 'paid'
                            ? 'Pagas a partir de'
                            : 'Vencimento a partir de'
                    "
                />
                <Input
                    v-model="filters.to"
                    type="date"
                    :aria-label="
                        filters.status === 'paid'
                            ? 'Pagas até'
                            : 'Vencimento até'
                    "
                />
                <NativeSelect
                    v-if="isPayable"
                    v-model="filters.supplier"
                    aria-label="Fornecedor"
                >
                    <option value="">Todos os fornecedores</option>
                    <option
                        v-for="supplier in suppliers"
                        :key="supplier.id"
                        :value="String(supplier.id)"
                    >
                        {{ supplier.name }}
                    </option>
                </NativeSelect>
                <NativeSelect
                    v-model="filters.category"
                    aria-label="Categoria"
                    :class="{ 'lg:col-span-2': !isPayable }"
                >
                    <option value="">Todas as categorias</option>
                    <option
                        v-for="category in categories"
                        :key="category.id"
                        :value="String(category.id)"
                    >
                        {{ category.name }}
                    </option>
                </NativeSelect>
            </div>
        </div>

        <p
            v-if="installments.data.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nada por aqui com esses filtros.
        </p>

        <ul
            v-if="installments.data.length > 0"
            class="flex flex-col divide-y rounded-2xl border bg-card shadow-xs md:hidden"
        >
            <li
                v-for="row in installments.data"
                :key="row.id"
                class="flex flex-col gap-2 p-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <Link
                        :href="show(row.titleId)"
                        class="min-w-0 font-medium hover:underline"
                    >
                        {{ row.description }}
                        <span
                            class="block text-xs font-normal text-muted-foreground"
                        >
                            <template v-if="row.count > 1"
                                >Parcela {{ row.number }}/{{
                                    row.count
                                }}</template
                            >
                            <template v-if="row.count > 1 && row.party">
                                ·
                            </template>
                            {{ row.party }}
                        </span>
                    </Link>
                    <span
                        class="font-semibold whitespace-nowrap tabular-nums"
                        >{{
                            formatCents(row.paidCents ?? row.amountCents)
                        }}</span
                    >
                </div>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-muted-foreground">
                        {{ row.status === 'paid' ? 'Em' : 'Vence' }}
                        {{
                            formatDate(
                                row.status === 'paid' ? row.paidOn : row.dueOn,
                            )
                        }}
                    </span>
                    <InstallmentStatusBadge
                        :status="row.status"
                        :label="
                            row.status === 'overdue'
                                ? `${row.statusLabel} há ${row.daysLate} dia(s)`
                                : row.statusLabel
                        "
                    />
                    <Button
                        v-if="row.status === 'open' || row.status === 'overdue'"
                        size="sm"
                        class="ml-auto"
                        @click="settle(row)"
                        >{{ words.verb }}</Button
                    >
                    <Button
                        v-else-if="row.status === 'paid'"
                        size="sm"
                        variant="ghost"
                        class="ml-auto"
                        @click="undo(row)"
                        ><Undo2 /> Desfazer</Button
                    >
                </div>
            </li>
            <li class="flex justify-between p-4 text-sm">
                <span class="text-muted-foreground"
                    >{{ installments.total }} parcela(s)</span
                >
                <span class="font-semibold tabular-nums">{{
                    formatCents(totalCents)
                }}</span>
            </li>
        </ul>

        <div
            v-if="installments.data.length > 0"
            class="hidden overflow-x-auto rounded-2xl border bg-card shadow-xs md:block"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">
                            {{
                                filters.status === 'paid'
                                    ? 'Data'
                                    : 'Vencimento'
                            }}
                        </th>
                        <th class="px-4 py-2.5 font-medium">Descrição</th>
                        <th
                            class="hidden px-4 py-2.5 font-medium md:table-cell"
                        >
                            Categoria
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Valor
                        </th>
                        <th class="px-4 py-2.5 font-medium">Situação</th>
                        <th class="px-4 py-2.5">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in installments.data"
                        :key="row.id"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="font-medium">{{
                                formatDate(
                                    row.status === 'paid'
                                        ? row.paidOn
                                        : row.dueOn,
                                )
                            }}</span>
                            <span
                                v-if="row.status === 'overdue'"
                                class="block text-xs text-destructive"
                                >há {{ row.daysLate }} dia(s)</span
                            >
                            <span
                                v-else-if="row.status === 'paid'"
                                class="block text-xs text-muted-foreground"
                                >vencia {{ formatDate(row.dueOn) }}</span
                            >
                        </td>
                        <td class="px-4 py-3">
                            <Link
                                :href="show(row.titleId)"
                                class="font-medium hover:underline"
                                >{{ row.description }}</Link
                            >
                            <span class="block text-xs text-muted-foreground">
                                <template v-if="row.count > 1"
                                    >Parcela {{ row.number }}/{{ row.count }}
                                </template>
                                <template v-if="row.count > 1 && row.party">
                                    ·
                                </template>
                                <template v-if="row.party">{{
                                    row.party
                                }}</template>
                                <template v-if="row.documentNumber">
                                    · Doc. {{ row.documentNumber }}</template
                                >
                            </span>
                        </td>
                        <td
                            class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                        >
                            {{ row.category ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <span class="font-medium tabular-nums">{{
                                formatCents(row.paidCents ?? row.amountCents)
                            }}</span>
                            <span
                                v-if="row.paymentMethod"
                                class="block text-xs text-muted-foreground"
                            >
                                <Zap
                                    v-if="row.automatic"
                                    class="inline size-3"
                                    aria-label="Baixa automática"
                                />
                                {{ row.paymentMethod }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <InstallmentStatusBadge
                                :status="row.status"
                                :label="row.statusLabel"
                            />
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button
                                v-if="
                                    row.status === 'open' ||
                                    row.status === 'overdue'
                                "
                                size="sm"
                                @click="settle(row)"
                                >{{ words.verb }}</Button
                            >
                            <Button
                                v-else-if="row.status === 'paid'"
                                size="sm"
                                variant="ghost"
                                @click="undo(row)"
                            >
                                <Undo2 /> Desfazer
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                as-child
                                class="ml-1"
                            >
                                <Link
                                    :href="show(row.titleId)"
                                    :aria-label="`Ver ${row.description}`"
                                    ><Eye
                                /></Link>
                            </Button>
                        </td>
                    </tr>
                </tbody>
                <tfoot class="border-t bg-muted/30">
                    <tr>
                        <td
                            colspan="3"
                            class="px-4 py-2.5 text-sm text-muted-foreground"
                        >
                            {{ installments.total }} parcela(s) com esses
                            filtros
                        </td>
                        <td
                            class="px-4 py-2.5 text-right font-semibold tabular-nums"
                        >
                            {{ formatCents(totalCents) }}
                        </td>
                        <td colspan="2" />
                    </tr>
                </tfoot>
            </table>
        </div>

        <nav
            v-if="installments.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in installments.links" :key="link.label">
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

    <SettleDialog
        v-model:open="settleOpen"
        :installment="settling"
        :payment-methods="paymentMethods"
        :today="today"
        :cash-open="cashOpen"
    />
</template>
