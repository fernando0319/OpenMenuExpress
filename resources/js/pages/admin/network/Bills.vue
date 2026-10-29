<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { RotateCcw } from '@lucide/vue';
import { ref } from 'vue';
import NetworkFinanceAdminController from '@/actions/App/Http/Controllers/Admin/NetworkFinanceAdminController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index as networkIndex } from '@/routes/admin/network';
import { index } from '@/routes/admin/network/bills';
import { show as storeShow } from '@/routes/admin/stores';
import type { Paginated } from '@/types';

type BillRow = {
    id: number;
    storeId: number;
    storeName: string;
    period: string;
    cycle: string;
    count: number;
    amountCents: number;
    dueAt: string;
    status: 'open' | 'paid' | 'waived';
    statusLabel: string;
    overdue: boolean;
    note: string | null;
};

defineProps<{
    bills: Paginated<BillRow>;
    totalCents: number;
    filters: { status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Rede de entregadores', href: networkIndex() },
            { title: 'Faturas das lojas', href: index() },
        ],
    },
});

const statuses = [
    { value: 'open', label: 'Em aberto' },
    { value: 'paid', label: 'Pagas' },
    { value: 'waived', label: 'Dispensadas' },
    { value: 'all', label: 'Todas' },
];

function filter(status: string): void {
    router.get(
        index.url({
            query: { status: status === 'open' ? undefined : status },
        }),
        {},
        { preserveScroll: true, replace: true },
    );
}

const dialogOpen = ref(false);
const settling = ref<BillRow | null>(null);
const form = useForm({ status: 'paid', note: '' });

function startSettle(bill: BillRow, status: 'paid' | 'waived'): void {
    settling.value = bill;
    form.clearErrors();
    form.status = status;
    form.note = '';
    dialogOpen.value = true;
}

function settle(): void {
    if (settling.value) {
        form.post(
            NetworkFinanceAdminController.settleBill.url(settling.value.id),
            {
                preserveScroll: true,
                onSuccess: () => (dialogOpen.value = false),
            },
        );
    }
}

function reopen(bill: BillRow): void {
    if (confirm(`Reabrir a fatura de ${bill.storeName} (${bill.period})?`)) {
        router.post(
            NetworkFinanceAdminController.reopenBill.url(bill.id),
            {},
            { preserveScroll: true },
        );
    }
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });
</script>

<template>
    <Head title="Faturas das lojas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Faturas das lojas"
            description="Corridas entregues, fechadas por dia ou por semana conforme a loja escolheu. Registre aqui os PIX recebidos."
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
                </button>
            </div>
            <span class="ml-auto text-sm text-muted-foreground"
                >Total:
                <strong class="text-foreground">{{
                    formatCents(totalCents)
                }}</strong></span
            >
        </div>

        <p
            v-if="bills.data.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhuma fatura aqui.
        </p>

        <div
            v-else
            class="overflow-x-auto rounded-2xl border bg-card shadow-xs"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Loja</th>
                        <th class="px-4 py-2.5 font-medium">Período</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Corridas
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Valor
                        </th>
                        <th class="px-4 py-2.5 font-medium">Vencimento</th>
                        <th class="px-4 py-2.5 font-medium">Situação</th>
                        <th class="px-4 py-2.5">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="bill in bills.data" :key="bill.id">
                        <td class="px-4 py-2.5">
                            <Link
                                :href="storeShow(bill.storeId)"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ bill.storeName }}</Link
                            >
                        </td>
                        <td class="px-4 py-2.5">
                            {{ bill.period }}
                            <span class="text-xs text-muted-foreground"
                                >({{ bill.cycle }})</span
                            >
                        </td>
                        <td class="px-4 py-2.5 text-right">{{ bill.count }}</td>
                        <td class="px-4 py-2.5 text-right font-medium">
                            {{ formatCents(bill.amountCents) }}
                        </td>
                        <td class="px-4 py-2.5">
                            {{ date.format(new Date(bill.dueAt)) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <Badge
                                :variant="
                                    bill.overdue
                                        ? 'destructive'
                                        : bill.status === 'open'
                                          ? 'outline'
                                          : 'secondary'
                                "
                                >{{ bill.statusLabel }}</Badge
                            >
                            <p
                                v-if="bill.note"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ bill.note }}
                            </p>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <template v-if="bill.status === 'open'">
                                <Button
                                    size="sm"
                                    @click="startSettle(bill, 'paid')"
                                    >Recebi</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="startSettle(bill, 'waived')"
                                    >Dispensar</Button
                                >
                            </template>
                            <Button
                                v-else
                                size="icon"
                                variant="ghost"
                                aria-label="Reabrir"
                                @click="reopen(bill)"
                            >
                                <RotateCcw />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="bills.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in bills.links" :key="link.label">
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

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    form.status === 'paid'
                        ? 'Registrar pagamento'
                        : 'Dispensar fatura'
                }}</DialogTitle>
                <DialogDescription v-if="settling"
                    >{{ settling.storeName }} · {{ settling.period }} ·
                    {{ formatCents(settling.amountCents) }}</DialogDescription
                >
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="note">Observação (opcional)</Label>
                <Input
                    id="note"
                    v-model="form.note"
                    maxlength="255"
                    placeholder="Ex.: PIX recebido em 27/10"
                />
                <InputError :message="form.errors.note" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false"
                    >Voltar</Button
                >
                <Button :disabled="form.processing" @click="settle"
                    >Confirmar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
