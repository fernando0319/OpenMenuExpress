<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { RotateCcw, X } from '@lucide/vue';
import { ref } from 'vue';
import InvoiceAdminController from '@/actions/App/Http/Controllers/Admin/InvoiceAdminController';
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
import { index } from '@/routes/admin/invoices';
import { show as storeShow } from '@/routes/admin/stores';
import type { Paginated } from '@/types';

type InvoiceRow = {
    id: number;
    storeId: number;
    storeName: string;
    period: string;
    salesCents: number;
    amountCents: number;
    status: 'open' | 'paid' | 'waived';
    statusLabel: string;
    overdue: boolean;
    dueAt: string;
    note: string | null;
    settledAt: string | null;
};

const props = defineProps<{
    invoices: Paginated<InvoiceRow>;
    dueTotalCents: number;
    filters: { status: string; store: number | null };
    storeName: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Faturas', href: index() },
        ],
    },
});

const statuses = [
    { value: 'due', label: 'A receber' },
    { value: 'paid', label: 'Pagas' },
    { value: 'waived', label: 'Dispensadas' },
    { value: 'all', label: 'Todas' },
];

function filter(
    status: string,
    store: number | null = props.filters.store,
): void {
    router.get(
        index.url({
            query: {
                status: status === 'due' ? undefined : status,
                store: store ?? undefined,
            },
        }),
        {},
        { preserveScroll: true, replace: true },
    );
}

const settleOpen = ref(false);
const settling = ref<InvoiceRow | null>(null);
const form = useForm({ status: 'paid', note: '' });

function startSettle(invoice: InvoiceRow, status: 'paid' | 'waived'): void {
    settling.value = invoice;
    form.clearErrors();
    form.status = status;
    form.note = '';
    settleOpen.value = true;
}

function settle(): void {
    if (!settling.value) {
        return;
    }

    form.post(InvoiceAdminController.settle.url(settling.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            settleOpen.value = false;
        },
    });
}

function reopen(invoice: InvoiceRow): void {
    if (
        confirm(
            `Reabrir a fatura de ${invoice.period} da ${invoice.storeName}?`,
        )
    ) {
        router.post(
            InvoiceAdminController.reopen.url(invoice.id),
            {},
            { preserveScroll: true },
        );
    }
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });
</script>

<template>
    <Head title="Faturas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Faturas"
            description="Meses fechados dos planos cobrados por percentual. O pagamento é recebido por fora; aqui você registra."
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
            <Badge v-if="storeName" variant="secondary" class="gap-1">
                {{ storeName }}
                <button
                    type="button"
                    aria-label="Ver todas as lojas"
                    @click="filter(filters.status, null)"
                >
                    <X class="size-3.5" />
                </button>
            </Badge>
            <span class="ml-auto text-sm text-muted-foreground">
                A receber:
                <strong class="text-foreground">{{
                    formatCents(dueTotalCents)
                }}</strong>
            </span>
        </div>

        <p
            v-if="invoices.data.length === 0"
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
                        <th class="px-4 py-2.5 font-medium">Mês</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Vendas online
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
                        v-for="invoice in invoices.data"
                        :key="invoice.id"
                        class="border-t"
                    >
                        <td class="px-4 py-3">
                            <Link
                                :href="storeShow(invoice.storeId)"
                                class="font-medium hover:underline"
                                >{{ invoice.storeName }}</Link
                            >
                        </td>
                        <td class="px-4 py-3 first-letter:uppercase">
                            {{ invoice.period }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            {{ formatCents(invoice.salesCents) }}
                        </td>
                        <td
                            class="px-4 py-3 text-right font-medium tabular-nums"
                        >
                            {{ formatCents(invoice.amountCents) }}
                        </td>
                        <td class="px-4 py-3">
                            <Badge
                                :variant="
                                    invoice.overdue
                                        ? 'destructive'
                                        : invoice.status === 'open' &&
                                            invoice.amountCents > 0
                                          ? 'outline'
                                          : 'secondary'
                                "
                                >{{
                                    invoice.overdue
                                        ? 'Vencida (loja bloqueada)'
                                        : invoice.statusLabel
                                }}</Badge
                            >
                            <span
                                v-if="invoice.note"
                                class="block text-xs text-muted-foreground"
                                >{{ invoice.note }}</span
                            >
                            <span
                                v-else-if="invoice.settledAt"
                                class="block text-xs text-muted-foreground"
                                >{{
                                    date.format(new Date(invoice.settledAt))
                                }}</span
                            >
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <template
                                v-if="
                                    invoice.status === 'open' &&
                                    invoice.amountCents > 0
                                "
                            >
                                <Button
                                    size="sm"
                                    @click="startSettle(invoice, 'paid')"
                                    >Marcar paga</Button
                                >
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    @click="startSettle(invoice, 'waived')"
                                    >Dispensar</Button
                                >
                            </template>
                            <Button
                                v-else-if="invoice.status !== 'open'"
                                size="sm"
                                variant="ghost"
                                @click="reopen(invoice)"
                            >
                                <RotateCcw /> Reabrir
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="invoices.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in invoices.links" :key="link.label">
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

    <Dialog v-model:open="settleOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    form.status === 'paid'
                        ? 'Registrar pagamento'
                        : 'Dispensar fatura'
                }}</DialogTitle>
                <DialogDescription v-if="settling">
                    {{ settling.storeName }} · {{ settling.period }} ·
                    {{ formatCents(settling.amountCents) }}
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="settle">
                <div class="grid gap-2">
                    <Label for="invoice-note">Observação (opcional)</Label>
                    <Input
                        id="invoice-note"
                        v-model="form.note"
                        maxlength="255"
                        :placeholder="
                            form.status === 'paid'
                                ? 'Ex.: PIX recebido em 03/11'
                                : 'Ex.: cortesia de inauguração'
                        "
                    />
                    <InputError :message="form.errors.note" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{
                            form.status === 'paid'
                                ? 'Marcar como paga'
                                : 'Dispensar'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
