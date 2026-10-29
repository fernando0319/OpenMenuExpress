<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { formatCents } from '@/lib/money';
import { show as orderShow } from '@/routes/orders';
import type { CashSessionDetail } from '@/types/cash';

const props = defineProps<{
    session: CashSessionDetail;
}>();

const time = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? time.format(new Date(value)) : '';
}

const methodsWithSales = computed(() =>
    props.session.summary.byMethod.filter((row) => row.cents !== 0),
);

const cashSalesCents = computed(
    () =>
        props.session.summary.byMethod.find((row) => row.method === 'cash')
            ?.cents ?? 0,
);

function differenceLabel(cents: number): string {
    if (cents === 0) {
        return 'Caixa conferido, sem diferença';
    }

    return cents > 0
        ? `Sobra de ${formatCents(cents)}`
        : `Falta de ${formatCents(-cents)}`;
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Vendas</p>
                <p class="text-2xl font-semibold">
                    {{ formatCents(session.summary.netSalesCents) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ session.summary.ordersCount }} pedido(s)
                    <template v-if="session.summary.refundsCents > 0">
                        · estornos
                        {{
                            formatCents(session.summary.refundsCents)
                        }}</template
                    >
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">
                    Dinheiro esperado na gaveta
                </p>
                <p class="text-2xl font-semibold">
                    {{
                        formatCents(
                            session.expectedCashCents ??
                                session.summary.expectedCashCents,
                        )
                    }}
                </p>
            </div>
            <div
                v-if="session.differenceCents !== null"
                class="rounded-xl border p-4"
            >
                <p class="text-sm text-muted-foreground">
                    Contado no fechamento
                </p>
                <p class="text-2xl font-semibold">
                    {{ formatCents(session.countedCashCents ?? 0) }}
                </p>
                <p
                    class="text-xs font-medium"
                    :class="
                        session.differenceCents < 0
                            ? 'text-destructive'
                            : session.differenceCents > 0
                              ? 'text-amber-600'
                              : 'text-emerald-600'
                    "
                >
                    {{ differenceLabel(session.differenceCents) }}
                </p>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border p-4 text-sm">
                <h3 class="mb-2 font-medium">Vendas por forma de pagamento</h3>
                <p
                    v-if="methodsWithSales.length === 0"
                    class="text-muted-foreground"
                >
                    Nenhuma venda ainda.
                </p>
                <dl v-else class="grid gap-1">
                    <div
                        v-for="row in methodsWithSales"
                        :key="row.method"
                        class="flex justify-between"
                    >
                        <dt>{{ row.label }}</dt>
                        <dd>{{ formatCents(row.cents) }}</dd>
                    </div>
                </dl>
            </div>
            <div class="rounded-xl border p-4 text-sm">
                <h3 class="mb-2 font-medium">Dinheiro na gaveta</h3>
                <dl class="grid gap-1">
                    <div class="flex justify-between">
                        <dt>Fundo inicial</dt>
                        <dd>{{ formatCents(session.summary.openingCents) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Vendas em dinheiro</dt>
                        <dd>{{ formatCents(cashSalesCents) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Suprimentos</dt>
                        <dd>
                            {{ formatCents(session.summary.suppliesCents) }}
                        </dd>
                    </div>
                    <div class="flex justify-between">
                        <dt>Sangrias</dt>
                        <dd>
                            −
                            {{ formatCents(session.summary.withdrawalsCents) }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between border-t pt-1 font-semibold"
                    >
                        <dt>Esperado</dt>
                        <dd>
                            {{
                                formatCents(
                                    session.expectedCashCents ??
                                        session.summary.expectedCashCents,
                                )
                            }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <p
            v-if="session.notes"
            class="rounded-lg border p-3 text-sm text-muted-foreground"
        >
            Obs.: {{ session.notes }}
        </p>

        <div class="rounded-xl border text-sm">
            <h3 class="border-b p-4 font-medium">Movimentos</h3>
            <p
                v-if="session.movements.length === 0"
                class="p-4 text-muted-foreground"
            >
                Nenhum movimento ainda.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="movement in session.movements"
                    :key="movement.id"
                    class="flex items-center gap-3 px-4 py-2"
                >
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">
                            {{ movement.typeLabel }}
                            <span
                                v-if="movement.method"
                                class="font-normal text-muted-foreground"
                                >· {{ movement.method }}</span
                            >
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ formatDate(movement.at) }}
                            <template v-if="movement.by">
                                · {{ movement.by }}</template
                            >
                            <template v-if="movement.description">
                                ·
                                <Link
                                    v-if="movement.orderId"
                                    :href="orderShow(movement.orderId)"
                                    class="underline-offset-4 hover:underline"
                                    >{{ movement.description }}</Link
                                >
                                <template v-else>{{
                                    movement.description
                                }}</template>
                            </template>
                        </p>
                    </div>
                    <span
                        class="font-medium whitespace-nowrap"
                        :class="
                            movement.signedCents < 0
                                ? 'text-destructive'
                                : 'text-emerald-600'
                        "
                    >
                        {{ movement.signedCents < 0 ? '−' : '+' }}
                        {{ formatCents(Math.abs(movement.signedCents)) }}
                    </span>
                </li>
            </ul>
        </div>
    </div>
</template>
