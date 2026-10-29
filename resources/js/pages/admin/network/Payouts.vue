<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Copy, Download, RotateCcw } from '@lucide/vue';
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
import { show as courierShow } from '@/routes/admin/network/couriers';
import {
    exportMethod as exportPayouts,
    index,
} from '@/routes/admin/network/payouts';
import type { Paginated } from '@/types';

type PayoutRow = {
    id: number;
    courierId: number;
    courierName: string;
    period: string;
    count: number;
    amountCents: number;
    pixKeyType: string;
    pixKey: string;
    status: 'pending' | 'paid';
    paidAt: string | null;
    note: string | null;
};

defineProps<{
    payouts: Paginated<PayoutRow>;
    totalCents: number;
    filters: { status: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Rede de entregadores', href: networkIndex() },
            { title: 'Repasses', href: index() },
        ],
    },
});

const statuses = [
    { value: 'pending', label: 'A pagar' },
    { value: 'paid', label: 'Pagos' },
    { value: 'all', label: 'Todos' },
];

function filter(status: string): void {
    router.get(
        index.url({
            query: { status: status === 'pending' ? undefined : status },
        }),
        {},
        { preserveScroll: true, replace: true },
    );
}

const copiedId = ref<number | null>(null);

async function copyKey(payout: PayoutRow): Promise<void> {
    await navigator.clipboard.writeText(payout.pixKey);
    copiedId.value = payout.id;
    setTimeout(() => (copiedId.value = null), 2000);
}

const dialogOpen = ref(false);
const paying = ref<PayoutRow | null>(null);
const form = useForm({ note: '' });

function startPay(payout: PayoutRow): void {
    paying.value = payout;
    form.clearErrors();
    form.note = '';
    dialogOpen.value = true;
}

function pay(): void {
    if (paying.value) {
        form.post(
            NetworkFinanceAdminController.payPayout.url(paying.value.id),
            {
                preserveScroll: true,
                onSuccess: () => (dialogOpen.value = false),
            },
        );
    }
}

function reopen(payout: PayoutRow): void {
    if (confirm(`Marcar o repasse de ${payout.courierName} como não pago?`)) {
        router.post(
            NetworkFinanceAdminController.reopenPayout.url(payout.id),
            {},
            { preserveScroll: true },
        );
    }
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });
</script>

<template>
    <Head title="Repasses" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Repasses aos entregadores"
            description="Toda segunda-feira a semana anterior fecha. Faça o PIX para a chave de cada um e marque como pago."
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
            <Button variant="outline" size="sm" as-child>
                <a :href="exportPayouts.url()"
                    ><Download /> Planilha dos pendentes</a
                >
            </Button>
            <span class="ml-auto text-sm text-muted-foreground"
                >Total:
                <strong class="text-foreground">{{
                    formatCents(totalCents)
                }}</strong></span
            >
        </div>

        <p
            v-if="payouts.data.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhum repasse aqui.
        </p>

        <div
            v-else
            class="overflow-x-auto rounded-2xl border bg-card shadow-xs"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Entregador</th>
                        <th class="px-4 py-2.5 font-medium">Semana</th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Corridas
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            Valor
                        </th>
                        <th class="px-4 py-2.5 font-medium">Chave PIX</th>
                        <th class="px-4 py-2.5">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="payout in payouts.data" :key="payout.id">
                        <td class="px-4 py-2.5">
                            <Link
                                :href="courierShow(payout.courierId)"
                                class="font-medium underline-offset-4 hover:underline"
                                >{{ payout.courierName }}</Link
                            >
                        </td>
                        <td class="px-4 py-2.5">{{ payout.period }}</td>
                        <td class="px-4 py-2.5 text-right">
                            {{ payout.count }}
                        </td>
                        <td class="px-4 py-2.5 text-right font-medium">
                            {{ formatCents(payout.amountCents) }}
                        </td>
                        <td class="px-4 py-2.5">
                            <button
                                type="button"
                                class="flex items-center gap-1.5 text-left break-all"
                                @click="copyKey(payout)"
                            >
                                <span class="text-xs text-muted-foreground"
                                    >{{ payout.pixKeyType }}:</span
                                >
                                {{ payout.pixKey }}
                                <Copy
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                            </button>
                            <span
                                v-if="copiedId === payout.id"
                                class="text-xs text-emerald-600"
                                >Copiada</span
                            >
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <Button
                                v-if="payout.status === 'pending'"
                                size="sm"
                                @click="startPay(payout)"
                                >Paguei</Button
                            >
                            <template v-else>
                                <Badge variant="secondary"
                                    >Pago
                                    {{
                                        payout.paidAt
                                            ? date.format(
                                                  new Date(payout.paidAt),
                                              )
                                            : ''
                                    }}</Badge
                                >
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Desfazer"
                                    @click="reopen(payout)"
                                >
                                    <RotateCcw />
                                </Button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="payouts.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in payouts.links" :key="link.label">
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
                <DialogTitle>Confirmar repasse</DialogTitle>
                <DialogDescription v-if="paying"
                    >{{ paying.courierName }} ·
                    {{ formatCents(paying.amountCents) }} para
                    {{ paying.pixKeyType }}
                    {{ paying.pixKey }}</DialogDescription
                >
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="note">Observação (opcional)</Label>
                <Input
                    id="note"
                    v-model="form.note"
                    maxlength="255"
                    placeholder="Ex.: ID da transação"
                />
                <InputError :message="form.errors.note" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false"
                    >Voltar</Button
                >
                <Button :disabled="form.processing" @click="pay"
                    >Marcar como pago</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
