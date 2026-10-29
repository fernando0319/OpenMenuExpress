<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import {
    Ban,
    Pencil,
    RotateCcw,
    Trash2,
    Undo2,
    Wallet,
    Zap,
} from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import InstallmentController from '@/actions/App/Http/Controllers/Finance/InstallmentController';
import TitleController from '@/actions/App/Http/Controllers/Finance/TitleController';
import InstallmentStatusBadge from '@/components/finance/InstallmentStatusBadge.vue';
import SettleDialog from '@/components/finance/SettleDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, paidWord } from '@/lib/finance';
import { formatCents } from '@/lib/money';
import { overview, payables, receivables } from '@/routes/finance';
import { edit } from '@/routes/finance/titles';
import type { FinanceKind, InstallmentRow, Option } from '@/types/finance';

const props = defineProps<{
    title: {
        id: number;
        type: FinanceKind;
        typeLabel: string;
        description: string;
        party: string | null;
        category: string | null;
        documentNumber: string | null;
        issuedOn: string;
        totalCents: number;
        installmentsCount: number;
        frequencyLabel: string;
        autoSettle: boolean;
        autoSettleMethodLabel: string | null;
        notes: string | null;
        canceled: boolean;
    };
    installments: InstallmentRow[];
    paidCents: number;
    openCents: number;
    hasPaid: boolean;
    paymentMethods: Option[];
    today: string;
    cashOpen: boolean;
}>();

const isPayable = computed(() => props.title.type === 'payable');
const words = computed(() => paidWord(props.title.type));

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Financeiro', href: overview() },
            {
                title: isPayable.value ? 'Contas a pagar' : 'Contas a receber',
                href: isPayable.value ? payables() : receivables(),
            },
            { title: props.title.description, href: '#' },
        ],
    });
});

const progress = computed(() =>
    props.title.totalCents > 0
        ? Math.min(
              100,
              Math.round((props.paidCents / props.title.totalCents) * 100),
          )
        : 0,
);

const settleOpen = ref(false);
const settling = ref<InstallmentRow | null>(null);

function settle(row: InstallmentRow): void {
    settling.value = row;
    settleOpen.value = true;
}

function undo(row: InstallmentRow): void {
    if (confirm('Desfazer esta baixa? A parcela volta a ficar em aberto.')) {
        router.delete(InstallmentController.undo.url(row.id), {
            preserveScroll: true,
        });
    }
}

function cancel(): void {
    if (
        confirm(
            'Cancelar as parcelas em aberto? As já baixadas continuam no histórico.',
        )
    ) {
        router.post(
            TitleController.cancel.url(props.title.id),
            {},
            { preserveScroll: true },
        );
    }
}

function reopen(): void {
    router.post(
        TitleController.reopen.url(props.title.id),
        {},
        { preserveScroll: true },
    );
}

function destroy(): void {
    if (confirm('Excluir este lançamento e todas as parcelas?')) {
        router.delete(TitleController.destroy.url(props.title.id));
    }
}
</script>

<template>
    <Head :title="title.description" />

    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="mb-1 flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{{ title.typeLabel }}</Badge>
                    <Badge v-if="title.canceled" variant="destructive"
                        >Cancelado</Badge
                    >
                </div>
                <h1 class="text-xl font-semibold tracking-tight">
                    {{ title.description }}
                </h1>
                <p v-if="title.party" class="text-sm text-muted-foreground">
                    {{ title.party }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" as-child>
                    <Link :href="edit(title.id)"><Pencil /> Editar</Link>
                </Button>
                <Button
                    v-if="!title.canceled && openCents > 0"
                    variant="outline"
                    @click="cancel"
                >
                    <Ban /> Cancelar em aberto
                </Button>
                <Button v-if="title.canceled" variant="outline" @click="reopen">
                    <RotateCcw /> Reabrir
                </Button>
                <Button v-if="!hasPaid" variant="ghost" @click="destroy">
                    <Trash2 /> Excluir
                </Button>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
            <section class="rounded-2xl border bg-card p-5 shadow-xs">
                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-muted-foreground">Valor total</dt>
                        <dd class="text-lg font-semibold">
                            {{ formatCents(title.totalCents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Condição</dt>
                        <dd>
                            {{
                                title.installmentsCount > 1
                                    ? `${title.installmentsCount}x ${title.frequencyLabel.toLowerCase()}`
                                    : 'À vista'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">
                            {{ isPayable ? 'Data da compra' : 'Data da venda' }}
                        </dt>
                        <dd>{{ formatDate(title.issuedOn) }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Categoria</dt>
                        <dd>{{ title.category ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Nº do documento</dt>
                        <dd>{{ title.documentNumber ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Baixa automática</dt>
                        <dd class="flex items-center gap-1">
                            <template v-if="title.autoSettle">
                                <Zap class="size-3.5 text-primary" />
                                {{ title.autoSettleMethodLabel }}
                            </template>
                            <template v-else>Não</template>
                        </dd>
                    </div>
                </dl>
                <p
                    v-if="title.notes"
                    class="mt-4 rounded-lg bg-muted p-3 text-sm whitespace-pre-line"
                >
                    {{ title.notes }}
                </p>
            </section>

            <section
                class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <h2 class="font-semibold">Andamento</h2>
                <div
                    class="h-2 overflow-hidden rounded-full bg-muted"
                    role="img"
                    :aria-label="`${progress}% ${isPayable ? 'pago' : 'recebido'}`"
                >
                    <div
                        class="h-full rounded-full bg-primary"
                        :style="{ width: `${progress}%` }"
                    />
                </div>
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div>
                        <dt class="text-muted-foreground">{{ words.past }}</dt>
                        <dd class="font-semibold">
                            {{ formatCents(paidCents) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Em aberto</dt>
                        <dd class="font-semibold">
                            {{ formatCents(openCents) }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section class="overflow-x-auto rounded-2xl border bg-card shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Parcela</th>
                        <th class="px-4 py-2.5 font-medium">Vencimento</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Valor
                        </th>
                        <th class="px-4 py-2.5 font-medium">Baixa</th>
                        <th class="px-4 py-2.5 font-medium">Situação</th>
                        <th class="px-4 py-2.5">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in installments"
                        :key="row.id"
                        class="border-t align-top"
                    >
                        <td class="px-4 py-3">
                            {{ row.number }}/{{ row.count }}
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ formatDate(row.dueOn) }}
                            <span
                                v-if="row.status === 'overdue'"
                                class="block text-xs text-destructive"
                                >há {{ row.daysLate }} dia(s)</span
                            >
                        </td>
                        <td
                            class="px-4 py-3 text-right whitespace-nowrap tabular-nums"
                        >
                            {{ formatCents(row.amountCents) }}
                        </td>
                        <td class="px-4 py-3">
                            <template v-if="row.paidOn">
                                <span class="tabular-nums">{{
                                    formatCents(row.paidCents ?? 0)
                                }}</span>
                                em {{ formatDate(row.paidOn) }}
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    <Zap
                                        v-if="row.automatic"
                                        class="inline size-3"
                                        aria-label="Automática"
                                    />
                                    {{ row.paymentMethod }}
                                    <template v-if="row.throughCash">
                                        · <Wallet class="inline size-3" /> pelo
                                        caixa</template
                                    >
                                    <template v-if="row.notes">
                                        · {{ row.notes }}</template
                                    >
                                </span>
                            </template>
                            <span v-else class="text-muted-foreground">—</span>
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
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>

    <SettleDialog
        v-model:open="settleOpen"
        :installment="settling"
        :payment-methods="paymentMethods"
        :today="today"
        :cash-open="cashOpen"
    />
</template>
