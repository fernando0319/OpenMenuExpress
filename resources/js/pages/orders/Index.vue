<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Bike, Eye } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import NetworkDeliveryController from '@/actions/App/Http/Controllers/Orders/NetworkDeliveryController';
import OrderController from '@/actions/App/Http/Controllers/Orders/OrderController';
import Heading from '@/components/Heading.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import CourierPickerDialog from '@/components/orders/CourierPickerDialog.vue';
import type { NetworkPanel } from '@/components/orders/NetworkDeliveryPanel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { index, show } from '@/routes/orders';
import type { Paginated } from '@/types';

type OrderRow = {
    id: number;
    number: number;
    customerName: string;
    fulfillment: string;
    isDelivery: boolean;
    courierId: number | null;
    courierName: string | null;
    networkActive: boolean;
    deliveryFeeCents: number;
    status: string;
    statusLabel: string;
    nextAction: { value: string; label: string } | null;
    totalCents: number;
    placedAt: string;
    scheduledFor: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pedidos', href: index() }],
    },
});

const props = defineProps<{
    orders: Paginated<OrderRow>;
    pendingCount: number;
    statuses: { value: string; label: string }[];
    filters: { status: string };
    couriers: { id: number; name: string; role: string }[];
    network: {
        feeCents: number | null;
        nearby: number;
        radiusKm: number;
    } | null;
}>();

// New orders show up within 10 s on any server; no WebSocket needed.
// The alert sound lives in the panel layout (PendingOrdersAlert), so it rings on every page.
usePoll(10_000, { only: ['orders', 'pendingCount'] });

const status = ref(props.filters.status);

watch(status, (value) => {
    router.get(
        index.url({ query: { status: value === 'open' ? undefined : value } }),
        {},
        { preserveState: true, replace: true },
    );
});

const pickerOpen = ref(false);
const pickerOrder = ref<OrderRow | null>(null);

function send(
    order: OrderRow,
    status: string,
    courierId: number | null = null,
): void {
    router.patch(
        OrderController.updateStatus.url(order.id),
        { status, courier_id: courierId },
        { preserveScroll: true, only: ['orders', 'pendingCount', 'flash'] },
    );
}

function advance(order: OrderRow): void {
    if (!order.nextAction) {
        return;
    }

    // The network courier marks the pick-up; sending it out before cancels the search.
    if (
        order.nextAction.value === 'out_for_delivery' &&
        order.networkActive &&
        order.courierId === null &&
        !confirm(
            'A rede ainda está procurando um entregador. O pedido sai para entrega sozinho quando ele retirar na loja.\n\nMarcar como saiu agora cancela a corrida (vai sair com outro entregador). Continuar?',
        )
    ) {
        return;
    }

    // Leaving for delivery without a courier: ask who takes it.
    if (
        order.nextAction.value === 'out_for_delivery' &&
        order.isDelivery &&
        order.courierId === null &&
        !order.networkActive &&
        (props.couriers.length > 0 || props.network !== null)
    ) {
        pickerOrder.value = order;
        pickerOpen.value = true;

        return;
    }

    send(order, order.nextAction.value);
}

function chooseCourier(courierId: number | null): void {
    if (pickerOrder.value) {
        send(pickerOrder.value, 'out_for_delivery', courierId);
    }
}

// The network courier comes to the store; the order goes out when they pick it up.
const pickerNetwork = computed<NetworkPanel | null>(() =>
    props.network && pickerOrder.value
        ? {
              blockedReason: null,
              defaultFeeCents:
                  props.network.feeCents ?? pickerOrder.value.deliveryFeeCents,
              nearby: props.network.nearby,
              radiusKm: props.network.radiusKm,
              delivery: null,
          }
        : null,
);

function callNetwork(feeCents: number): void {
    if (!pickerOrder.value) {
        return;
    }

    router.post(
        NetworkDeliveryController.store.url(pickerOrder.value.id),
        { fee_cents: feeCents },
        {
            preserveScroll: true,
            only: ['orders', 'flash'],
            onError: (errors) => {
                toast.error(
                    Object.values(errors)[0] ??
                        'Não foi possível chamar o entregador.',
                );
            },
        },
    );
}

const timeFormat = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
});
</script>

<template>
    <Head :title="pendingCount > 0 ? `(${pendingCount}) Pedidos` : 'Pedidos'" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Pedidos"
                description="A lista se atualiza sozinha a cada 10 segundos."
            />
            <div class="flex items-center gap-2">
                <NativeSelect
                    v-model="status"
                    aria-label="Filtrar pedidos"
                    class="max-w-56"
                >
                    <option value="open">Em andamento</option>
                    <option value="all">Todos</option>
                    <option
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </div>
        </div>

        <div
            v-if="orders.data.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhum pedido aqui por enquanto.
        </div>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nº</th>
                        <th class="px-4 py-2 font-medium">Cliente</th>
                        <th class="px-4 py-2 font-medium">Recebimento</th>
                        <th class="px-4 py-2 font-medium">Situação</th>
                        <th class="px-4 py-2 text-right font-medium">Total</th>
                        <th class="px-4 py-2 font-medium">Horário</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Ação</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="order in orders.data"
                        :key="order.id"
                        class="border-t hover:bg-muted/30"
                        :class="{
                            'bg-amber-50 dark:bg-amber-950/40':
                                order.status === 'pending',
                        }"
                    >
                        <td class="px-4 py-2 font-medium">
                            <Link
                                :href="show(order.id)"
                                class="underline-offset-4 hover:underline"
                            >
                                {{ order.number }}
                            </Link>
                        </td>
                        <td class="px-4 py-2">{{ order.customerName }}</td>
                        <td class="px-4 py-2">
                            {{ order.fulfillment }}
                            <span
                                v-if="order.courierName"
                                class="flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <Bike class="size-3" /> {{ order.courierName }}
                            </span>
                            <span
                                v-else-if="order.networkActive"
                                class="flex items-center gap-1 text-xs text-amber-600"
                            >
                                <Bike class="size-3" /> Procurando entregador da
                                rede…
                            </span>
                            <Badge
                                v-if="order.scheduledFor"
                                variant="outline"
                                class="ml-1"
                                >Agendado: {{ order.scheduledFor }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    order.status === 'pending'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ order.statusLabel }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2 text-right">
                            {{ formatCents(order.totalCents) }}
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ timeFormat.format(new Date(order.placedAt)) }}
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <Button
                                as-child
                                size="sm"
                                variant="ghost"
                                class="mr-1"
                            >
                                <Link :href="show(order.id)">
                                    <Eye />
                                    Ver pedido
                                </Link>
                            </Button>
                            <Button
                                v-if="order.nextAction"
                                size="sm"
                                :variant="
                                    order.status === 'pending'
                                        ? 'default'
                                        : 'outline'
                                "
                                @click="advance(order)"
                            >
                                {{
                                    order.status === 'pending'
                                        ? 'Aceitar'
                                        : order.nextAction.label
                                }}
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="orders.last_page > 1"
            class="flex flex-wrap gap-1"
            aria-label="Paginação"
        >
            <template v-for="link in orders.links" :key="link.label">
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

    <CourierPickerDialog
        v-model:open="pickerOpen"
        :order-number="pickerOrder?.number ?? null"
        :couriers="couriers"
        :network="pickerNetwork"
        @choose="chooseCourier"
        @call-network="callNetwork"
    />
</template>
