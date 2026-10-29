<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Pencil, Printer as PrinterIcon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import NetworkDeliveryController from '@/actions/App/Http/Controllers/Orders/NetworkDeliveryController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import CourierPickerDialog from '@/components/orders/CourierPickerDialog.vue';
import NetworkDeliveryPanel from '@/components/orders/NetworkDeliveryPanel.vue';
import type { NetworkPanel } from '@/components/orders/NetworkDeliveryPanel.vue';
import PaymentLines from '@/components/pos/PaymentLines.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { centsToInput, formatCents } from '@/lib/money';
import { paymentsReady, tallyPayments } from '@/lib/payments';
import type { PaymentLine } from '@/lib/payments';
import { edit, index } from '@/routes/orders';
import type { PaymentMethodOption } from '@/types/menu';

type StatusOption = { value: string; label: string };

type OrderDetail = {
    id: number;
    number: number;
    status: string;
    statusLabel: string;
    nextStatuses: StatusOption[];
    channel: string;
    fulfillment: string;
    placedAt: string;
    scheduledFor: string | null;
    customerName: string;
    customerPhone: string | null;
    address: {
        street: string;
        number: string | null;
        district: string | null;
        complement: string | null;
        reference: string | null;
        zip: string | null;
    } | null;
    paymentMethod: string;
    paidAt: string | null;
    payments: { method: string; cents: number }[];
    canReceive: boolean;
    courierId: number | null;
    courierName: string | null;
    canAssignCourier: boolean;
    changeForCents: number | null;
    notes: string | null;
    subtotalCents: number;
    deliveryFeeCents: number;
    discountCents: number;
    couponCode: string | null;
    totalCents: number;
    tabId: number | null;
    canEdit: boolean;
    receivedCents: number;
    balanceCents: number;
    changes: {
        id: number;
        by: string | null;
        at: string | null;
        reason: string | null;
        lines: string[];
        totalBeforeCents: number;
        totalAfterCents: number;
        refundedCents: number;
    }[];
    items: {
        id: number;
        name: string;
        quantity: number;
        unitCents: number;
        totalCents: number;
        notes: string | null;
        options: {
            group: string;
            name: string;
            quantity: number;
            priceCents: number;
        }[];
    }[];
    history: {
        id: number;
        status: string;
        by: string | null;
        at: string | null;
    }[];
    messages: {
        id: number;
        event: string;
        status: string;
        error: string | null;
        at: string;
    }[];
};

const props = defineProps<{
    order: OrderDetail;
    paymentMethods: PaymentMethodOption[];
    couriers: { id: number; name: string; role: string }[];
    network: NetworkPanel | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pedidos', href: index() }],
    },
});

usePoll(15_000, { only: ['order', 'network'] });

// While a courier of the network is on it, follow the run closely.
const networkActive = computed(() => props.network?.delivery?.active === true);
const networkPoll = usePoll(
    5_000,
    { only: ['order', 'network'] },
    { autoStart: false },
);

watch(
    networkActive,
    (running) => (running ? networkPoll.start() : networkPoll.stop()),
    { immediate: true },
);

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '';
}

const forwardStatuses = computed(() =>
    props.order.nextStatuses.filter((status) => status.value !== 'cancelled'),
);

const canCancel = computed(() =>
    props.order.nextStatuses.some((status) => status.value === 'cancelled'),
);

function printOrder(): void {
    router.post(
        OrderController.print.url(props.order.id),
        {},
        { preserveScroll: true },
    );
}

function moveTo(status: StatusOption): void {
    if (
        status.value === 'cancelled' &&
        !confirm(
            `Cancelar o pedido nº ${props.order.number}? O estoque dos produtos é devolvido.`,
        )
    ) {
        return;
    }

    // The network courier marks the pick-up; sending it out before cancels the search.
    if (
        status.value === 'out_for_delivery' &&
        props.network?.delivery?.status === 'searching' &&
        !confirm(
            'A rede ainda está procurando um entregador. O pedido sai para entrega sozinho quando ele retirar na loja.\n\nMarcar como saiu agora cancela a corrida (vai sair com outro entregador). Continuar?',
        )
    ) {
        return;
    }

    // Leaving for delivery without a courier: ask who takes it.
    if (
        status.value === 'out_for_delivery' &&
        props.order.canAssignCourier &&
        props.order.courierId === null &&
        !networkActive.value &&
        (props.couriers.length > 0 || props.network?.blockedReason === null)
    ) {
        pickerOpen.value = true;

        return;
    }

    sendStatus(status.value);
}

const pickerOpen = ref(false);

function sendStatus(status: string, courierId: number | null = null): void {
    router.patch(
        OrderController.updateStatus.url(props.order.id),
        { status, courier_id: courierId },
        { preserveScroll: true },
    );
}

// The network courier comes to the store; the order goes out when they pick it up.
function callNetwork(feeCents: number): void {
    router.post(
        NetworkDeliveryController.store.url(props.order.id),
        { fee_cents: feeCents },
        { preserveScroll: true },
    );
}

// After an edit raised a paid order, only the difference is received.
const dueCents = computed(() =>
    Math.max(0, props.order.totalCents - props.order.receivedCents),
);

function printChange(changeId: number): void {
    router.post(
        OrderController.printChange.url({
            order: props.order.id,
            change: changeId,
        }),
        {},
        { preserveScroll: true },
    );
}

const receiveOpen = ref(false);
const receiveLines = ref<PaymentLine[]>([]);
const receiveErrors = ref<Record<string, string>>({});
const receiving = ref(false);
const receiveTally = computed(() =>
    tallyPayments(receiveLines.value, dueCents.value),
);

function startReceive(): void {
    receiveErrors.value = {};
    receiveLines.value = [
        {
            method:
                props.paymentMethods.find(
                    (method) => method.label === props.order.paymentMethod,
                )?.value ?? 'cash',
            amount: centsToInput(dueCents.value),
        },
    ];
    receiveOpen.value = true;
}

function receive(): void {
    receiving.value = true;
    router.post(
        OrderController.receivePayment.url(props.order.id),
        { payments: receiveLines.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                receiveOpen.value = false;
            },
            onError: (bag) => {
                receiveErrors.value = bag;
            },
            onFinish: () => {
                receiving.value = false;
            },
        },
    );
}

function assignCourier(value: string | number | null | undefined): void {
    router.patch(
        OrderController.assignCourier.url(props.order.id),
        {
            courier_id:
                value === '' || value === null || value === undefined
                    ? null
                    : Number(value),
        },
        { preserveScroll: true },
    );
}

const messageStatusLabel: Record<string, string> = {
    pending: 'Enviando',
    sent: 'Enviada',
    failed: 'Falhou',
};
</script>

<template>
    <Head :title="`Pedido ${order.number}`" />

    <div class="grid max-w-5xl gap-6 p-4 lg:grid-cols-[1fr_20rem]">
        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <Heading
                    :title="`Pedido nº ${order.number}`"
                    :description="`${order.channel} · ${formatDate(order.placedAt)}`"
                />
                <Badge class="ml-auto">{{ order.statusLabel }}</Badge>
                <Button
                    v-if="order.canEdit"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="edit(order.id)"
                        ><Pencil /> Alterar pedido</Link
                    >
                </Button>
                <Button variant="outline" size="sm" @click="printOrder">
                    <PrinterIcon />
                    Imprimir
                </Button>
            </div>

            <div
                v-if="order.nextStatuses.length > 0"
                class="flex flex-wrap gap-2 rounded-xl border p-3"
            >
                <Button
                    v-for="(status, position) in forwardStatuses"
                    :key="status.value"
                    :variant="position === 0 ? 'default' : 'outline'"
                    @click="moveTo(status)"
                >
                    {{ status.label }}
                </Button>
                <Button
                    v-if="canCancel"
                    variant="ghost"
                    class="ml-auto text-destructive"
                    @click="moveTo({ value: 'cancelled', label: 'Cancelado' })"
                >
                    Cancelar pedido
                </Button>
            </div>

            <ul class="flex flex-col divide-y rounded-xl border">
                <li
                    v-for="item in order.items"
                    :key="item.id"
                    class="flex justify-between gap-3 p-4 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ item.quantity }}× {{ item.name }}
                        </p>
                        <p
                            v-for="option in item.options"
                            :key="`${option.group}-${option.name}`"
                            class="text-muted-foreground"
                        >
                            {{ option.group }}:
                            {{
                                option.quantity > 1
                                    ? `${option.quantity}× ${option.name}`
                                    : option.name
                            }}
                        </p>
                        <p v-if="item.notes" class="font-medium text-amber-600">
                            Obs.: {{ item.notes }}
                        </p>
                    </div>
                    <span class="whitespace-nowrap">{{
                        formatCents(item.totalCents)
                    }}</span>
                </li>
            </ul>

            <p
                v-if="order.notes"
                class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950"
            >
                <strong>Observações:</strong> {{ order.notes }}
            </p>

            <div
                v-if="order.changes.length > 0"
                class="rounded-xl border p-4 text-sm"
            >
                <h3 class="mb-3 font-medium">Alterações</h3>
                <ol class="flex flex-col gap-4">
                    <li
                        v-for="change in order.changes"
                        :key="change.id"
                        class="flex flex-col gap-1 border-l-2 border-amber-400 pl-3"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <span class="text-muted-foreground">
                                {{ formatDate(change.at) }}
                                <template v-if="change.by">
                                    · {{ change.by }}</template
                                >
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                class="h-7"
                                @click="printChange(change.id)"
                                ><PrinterIcon /> Reimprimir</Button
                            >
                        </div>
                        <ul>
                            <li
                                v-for="line in change.lines"
                                :key="line"
                                class="font-medium"
                            >
                                {{ line }}
                            </li>
                        </ul>
                        <p v-if="change.reason" class="text-muted-foreground">
                            Motivo: {{ change.reason }}
                        </p>
                        <p class="text-muted-foreground">
                            Total: {{ formatCents(change.totalBeforeCents) }} →
                            {{ formatCents(change.totalAfterCents) }}
                            <template v-if="change.refundedCents > 0">
                                · estorno de
                                {{ formatCents(change.refundedCents) }} no
                                caixa</template
                            >
                        </p>
                    </li>
                </ol>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl border p-4 text-sm">
                    <h3 class="mb-2 font-medium">Histórico</h3>
                    <ol class="flex flex-col gap-1">
                        <li
                            v-for="change in order.history"
                            :key="change.id"
                            class="flex justify-between gap-2"
                        >
                            <span>{{ change.status }}</span>
                            <span class="text-muted-foreground">
                                {{ formatDate(change.at) }}
                                <template v-if="change.by">
                                    · {{ change.by }}</template
                                >
                            </span>
                        </li>
                    </ol>
                </div>
                <div class="rounded-xl border p-4 text-sm">
                    <h3 class="mb-2 font-medium">Mensagens no WhatsApp</h3>
                    <p
                        v-if="order.messages.length === 0"
                        class="text-muted-foreground"
                    >
                        Nenhuma mensagem enviada.
                    </p>
                    <ol v-else class="flex flex-col gap-1">
                        <li v-for="message in order.messages" :key="message.id">
                            <div class="flex justify-between gap-2">
                                <span>{{ message.event }}</span>
                                <Badge
                                    :variant="
                                        message.status === 'failed'
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                    >{{
                                        messageStatusLabel[message.status] ??
                                        message.status
                                    }}</Badge
                                >
                            </div>
                            <p
                                v-if="message.error"
                                class="text-xs text-destructive"
                            >
                                {{ message.error }}
                            </p>
                        </li>
                    </ol>
                </div>
            </div>
        </section>

        <aside class="flex flex-col gap-4 text-sm">
            <div class="rounded-xl border p-4">
                <h3 class="mb-2 font-medium">Cliente</h3>
                <p>{{ order.customerName }}</p>
                <a
                    v-if="order.customerPhone"
                    :href="`https://wa.me/${order.customerPhone}`"
                    target="_blank"
                    rel="noopener"
                    class="text-primary underline-offset-4 hover:underline"
                    >{{ order.customerPhone }}</a
                >
            </div>

            <div class="rounded-xl border p-4">
                <h3 class="mb-2 font-medium">{{ order.fulfillment }}</h3>
                <p
                    v-if="order.scheduledFor"
                    class="mb-2 font-medium text-amber-600"
                >
                    Agendado para {{ order.scheduledFor }}
                </p>
                <template v-if="order.address">
                    <p>
                        {{ order.address.street }}, {{ order.address.number }}
                        <template v-if="order.address.complement">
                            — {{ order.address.complement }}</template
                        >
                    </p>
                    <p>{{ order.address.district }}</p>
                    <p
                        v-if="order.address.reference"
                        class="text-muted-foreground"
                    >
                        Ref.: {{ order.address.reference }}
                    </p>
                </template>
                <div
                    v-if="
                        order.canAssignCourier &&
                        couriers.length > 0 &&
                        !network?.delivery?.active
                    "
                    class="mt-3 grid gap-1 border-t pt-3"
                >
                    <label for="courier" class="text-xs text-muted-foreground"
                        >Entregador</label
                    >
                    <NativeSelect
                        id="courier"
                        :model-value="order.courierId ?? ''"
                        @update:model-value="assignCourier"
                    >
                        <option value="">Ninguém definido</option>
                        <option
                            v-for="courier in couriers"
                            :key="courier.id"
                            :value="courier.id"
                        >
                            {{ courier.name }} ({{ courier.role }})
                        </option>
                    </NativeSelect>
                </div>
                <p
                    v-else-if="order.courierName && !network?.delivery"
                    class="mt-3 border-t pt-3"
                >
                    Entregue por {{ order.courierName }}
                </p>
                <NetworkDeliveryPanel
                    v-if="network"
                    :order-id="order.id"
                    :network="network"
                />
            </div>

            <div class="rounded-xl border p-4">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 class="font-medium">Pagamento</h3>
                    <Badge :variant="order.paidAt ? 'secondary' : 'outline'">{{
                        order.paidAt ? 'Pago' : 'A receber'
                    }}</Badge>
                </div>
                <p>{{ order.paymentMethod }}</p>
                <ul
                    v-if="order.payments.length > 1"
                    class="text-muted-foreground"
                >
                    <li
                        v-for="payment in order.payments"
                        :key="payment.method"
                        class="flex justify-between"
                    >
                        <span>{{ payment.method }}</span>
                        <span>{{ formatCents(payment.cents) }}</span>
                    </li>
                </ul>
                <p
                    v-if="order.balanceCents > 0"
                    class="mt-2 rounded-lg bg-amber-500/10 p-2 text-amber-800 dark:text-amber-300"
                >
                    Pedido alterado: falta receber
                    <strong>{{ formatCents(order.balanceCents) }}</strong
                    >.
                </p>
                <p
                    v-else-if="order.balanceCents < 0"
                    class="mt-2 rounded-lg bg-amber-500/10 p-2 text-amber-800 dark:text-amber-300"
                >
                    Pedido alterado: devolver
                    <strong>{{ formatCents(-order.balanceCents) }}</strong> ao
                    cliente.
                </p>
                <Button
                    v-if="order.canReceive"
                    size="sm"
                    class="mt-2 w-full"
                    @click="startReceive"
                >
                    {{
                        order.balanceCents > 0
                            ? `Receber ${formatCents(dueCents)}`
                            : 'Receber pagamento'
                    }}
                </Button>
                <p v-if="order.changeForCents" class="text-muted-foreground">
                    Troco para {{ formatCents(order.changeForCents) }} ({{
                        formatCents(order.changeForCents - order.totalCents)
                    }})
                </p>
                <dl class="mt-3 grid gap-1 border-t pt-3">
                    <div class="flex justify-between">
                        <dt>Subtotal</dt>
                        <dd>{{ formatCents(order.subtotalCents) }}</dd>
                    </div>
                    <div
                        v-if="order.deliveryFeeCents > 0"
                        class="flex justify-between"
                    >
                        <dt>Entrega</dt>
                        <dd>{{ formatCents(order.deliveryFeeCents) }}</dd>
                    </div>
                    <div
                        v-if="order.discountCents > 0"
                        class="flex justify-between"
                    >
                        <dt>
                            Desconto<template v-if="order.couponCode">
                                ({{ order.couponCode }})</template
                            >
                        </dt>
                        <dd>− {{ formatCents(order.discountCents) }}</dd>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <dt>Total</dt>
                        <dd>{{ formatCents(order.totalCents) }}</dd>
                    </div>
                </dl>
            </div>
        </aside>
    </div>

    <CourierPickerDialog
        v-model:open="pickerOpen"
        :order-number="order.number"
        :couriers="couriers"
        :network="network"
        @choose="sendStatus('out_for_delivery', $event)"
        @call-network="callNetwork"
    />

    <Dialog v-model:open="receiveOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Receber pedido nº {{ order.number }}</DialogTitle>
            </DialogHeader>
            <PaymentLines
                v-model="receiveLines"
                :total-cents="dueCents"
                :methods="paymentMethods"
            />
            <p
                v-if="Object.keys(receiveErrors).length > 0"
                class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
            >
                {{ Object.values(receiveErrors)[0] }}
            </p>
            <DialogFooter>
                <Button
                    class="w-full"
                    :disabled="receiving || !paymentsReady(receiveTally)"
                    @click="receive"
                >
                    Confirmar recebimento
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
