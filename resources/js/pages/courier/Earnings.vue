<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, Clock } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { formatCents } from '@/lib/money';

defineProps<{
    open: { count: number; amountCents: number };
    payouts: {
        id: number;
        period: string;
        count: number;
        amountCents: number;
        status: 'pending' | 'paid';
        paidAt: string | null;
    }[];
    runs: {
        id: number;
        storeName: string;
        deliveredAt: string | null;
        courierCents: number;
    }[];
}>();

const when = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
});
const day = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });
</script>

<template>
    <Head title="Ganhos" />

    <section class="flex flex-col gap-1 rounded-2xl border bg-background p-5">
        <p class="text-sm text-muted-foreground">
            A receber no próximo repasse
        </p>
        <p class="text-3xl font-bold">{{ formatCents(open.amountCents) }}</p>
        <p class="text-xs text-muted-foreground">
            {{ open.count }} corrida(s) entregues ainda não fechadas. A semana
            (segunda a domingo) fecha na segunda-feira e o PIX é feito em
            seguida.
        </p>
    </section>

    <section class="flex flex-col gap-2">
        <h2 class="px-1 text-sm font-semibold">Repasses</h2>
        <p
            v-if="payouts.length === 0"
            class="rounded-2xl border border-dashed bg-background p-6 text-center text-sm text-muted-foreground"
        >
            Nenhum repasse fechado ainda.
        </p>
        <ul
            v-else
            class="flex flex-col divide-y rounded-2xl border bg-background"
        >
            <li
                v-for="payout in payouts"
                :key="payout.id"
                class="flex items-center gap-3 px-4 py-3"
            >
                <CheckCircle2
                    v-if="payout.status === 'paid'"
                    class="size-5 text-emerald-600"
                />
                <Clock v-else class="size-5 text-amber-600" />
                <div class="flex-1">
                    <p class="text-sm font-medium">
                        Semana {{ payout.period }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ payout.count }} corrida(s)
                        <template v-if="payout.paidAt">
                            · pago em
                            {{ day.format(new Date(payout.paidAt)) }}</template
                        >
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm font-semibold">
                        {{ formatCents(payout.amountCents) }}
                    </p>
                    <Badge
                        :variant="
                            payout.status === 'paid' ? 'secondary' : 'outline'
                        "
                        >{{
                            payout.status === 'paid' ? 'Pago' : 'A pagar'
                        }}</Badge
                    >
                </div>
            </li>
        </ul>
    </section>

    <section class="flex flex-col gap-2">
        <h2 class="px-1 text-sm font-semibold">Últimas corridas</h2>
        <p
            v-if="runs.length === 0"
            class="rounded-2xl border border-dashed bg-background p-6 text-center text-sm text-muted-foreground"
        >
            Suas corridas entregues aparecem aqui.
        </p>
        <ul
            v-else
            class="flex flex-col divide-y rounded-2xl border bg-background"
        >
            <li
                v-for="run in runs"
                :key="run.id"
                class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
            >
                <div>
                    <p class="font-medium">{{ run.storeName }}</p>
                    <p
                        v-if="run.deliveredAt"
                        class="text-xs text-muted-foreground"
                    >
                        {{ when.format(new Date(run.deliveredAt)) }}
                    </p>
                </div>
                <span class="font-semibold">{{
                    formatCents(run.courierCents)
                }}</span>
            </li>
        </ul>
    </section>
</template>
