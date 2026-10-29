<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import {
    Bike,
    CalendarClock,
    ChevronLeft,
    CircleCheck,
    MapPin,
    MessageCircle,
    Wallet,
} from '@lucide/vue';
import { computed, onMounted } from 'vue';
import LiveMap from '@/components/maps/LiveMap.vue';
import OnlinePaymentPanel from '@/components/menu/OnlinePaymentPanel.vue';
import type { OnlinePaymentInfo } from '@/components/menu/OnlinePaymentPanel.vue';
import { useMenuTheme, useRecentOrders } from '@/composables/useMenuStorage';
import { formatCents } from '@/lib/money';
import { show as menuShow } from '@/routes/menu';
import type { MapMarker, MapPoint, MapTiles } from '@/types/maps';
import type { MenuBranding } from '@/types/menu';

type PlacedOrder = {
    token: string;
    number: number;
    status: string;
    fulfillment: string;
    placedAt: string | null;
    scheduledFor: string | null;
    subtotalCents: number;
    deliveryFeeCents: number;
    discountCents: number;
    couponCode: string | null;
    totalCents: number;
    paymentMethod: string;
    changeForCents: number | null;
    address: string | null;
    items: {
        name: string;
        quantity: number;
        totalCents: number;
        notes: string | null;
        options: { group: string; name: string; quantity: number }[];
    }[];
};

const props = defineProps<{
    store: { name: string; slug: string; whatsapp: string | null };
    order: PlacedOrder;
    tracking: { courierName: string; position: MapPoint | null } | null;
    mapTiles: MapTiles;
    branding: MenuBranding;
    payment: OnlinePaymentInfo | null;
}>();

useMenuTheme(props.branding);

// The customer sees the status change (and the courier moving) without reloading.
usePoll(10_000, { only: ['order', 'tracking', 'payment'] });

// Listed later in "Meus pedidos" on this phone.
const recent = useRecentOrders(props.store.slug);
onMounted(() =>
    recent.remember({
        token: props.order.token,
        number: props.order.number,
        totalCents: props.order.totalCents,
        placedAt: props.order.placedAt ?? new Date().toISOString(),
    }),
);

const markers = computed<MapMarker[]>(() =>
    props.tracking?.position
        ? [
              {
                  id: 'courier',
                  lat: props.tracking.position.lat,
                  lng: props.tracking.position.lng,
                  label: props.tracking.courierName,
              },
          ]
        : [],
);

const time = new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' });

function optionsText(item: PlacedOrder['items'][number]): string {
    return item.options
        .map((option) =>
            option.quantity > 1
                ? `${option.quantity}× ${option.name}`
                : option.name,
        )
        .join(', ');
}
</script>

<template>
    <Head :title="`Pedido ${order.number}`" />

    <div class="min-h-svh bg-brand-surface pb-10 font-display text-brand-ink">
        <header
            class="relative overflow-hidden rounded-b-[2.5rem] bg-linear-to-br from-brand to-brand-accent px-5 pt-[max(1rem,env(safe-area-inset-top))] pb-16 text-white"
        >
            <div class="mx-auto flex max-w-lg items-center gap-3">
                <Link
                    :href="menuShow(store.slug)"
                    class="grid size-10 place-items-center rounded-full hover:bg-white/10"
                    aria-label="Voltar ao cardápio"
                >
                    <ChevronLeft class="size-6" />
                </Link>
                <p class="flex-1 truncate pr-10 text-center font-semibold">
                    {{ store.name }}
                </p>
            </div>
            <div
                class="mx-auto mt-6 flex max-w-lg flex-col items-center gap-2 text-center"
            >
                <img
                    v-if="branding.logoUrl"
                    :src="branding.logoUrl"
                    :alt="`Logo de ${store.name}`"
                    class="size-16 rounded-2xl object-cover ring-4 ring-white/40"
                />
                <CircleCheck v-else class="size-14" />
                <h1 class="text-2xl font-semibold">
                    Pedido nº {{ order.number }}
                </h1>
                <p class="max-w-xs text-sm text-white/85">
                    Você recebe as atualizações pelo WhatsApp. Esta página
                    também se atualiza sozinha.
                </p>
            </div>
        </header>

        <main
            class="relative z-10 mx-auto -mt-10 flex max-w-lg flex-col gap-4 px-4"
        >
            <section
                class="flex items-center justify-between gap-3 rounded-3xl bg-white p-5 shadow-soft"
            >
                <span class="text-sm text-muted-foreground">Situação</span>
                <span
                    class="rounded-full bg-brand/10 px-4 py-1.5 text-sm font-semibold text-brand"
                    >{{ order.status }}</span
                >
            </section>

            <OnlinePaymentPanel
                v-if="payment"
                :payment="payment"
                :store-slug="store.slug"
                :order-token="order.token"
            />

            <section
                v-if="tracking"
                class="flex flex-col gap-3 rounded-3xl bg-white p-4 shadow-soft"
            >
                <p class="flex items-center gap-2 text-sm font-medium">
                    <Bike class="size-4 text-brand" />
                    {{ tracking.courierName }} está a caminho
                    <span
                        v-if="tracking.position"
                        class="font-normal text-muted-foreground"
                        >· atualizado às
                        {{ time.format(new Date(tracking.position.at)) }}</span
                    >
                </p>
                <LiveMap
                    v-if="tracking.position"
                    :tiles="mapTiles"
                    :markers="markers"
                />
                <p
                    v-else
                    class="rounded-2xl bg-brand-surface p-4 text-center text-sm text-muted-foreground"
                >
                    A localização do entregador aparece aqui assim que ele
                    compartilhar.
                </p>
            </section>

            <section
                class="flex flex-col gap-3 rounded-3xl bg-white p-5 text-sm shadow-soft"
            >
                <p
                    v-if="order.scheduledFor"
                    class="flex items-center gap-2 font-medium text-amber-700"
                >
                    <CalendarClock class="size-4" /> Agendado para
                    {{ order.scheduledFor }}
                </p>
                <p class="flex items-start gap-2">
                    <MapPin class="mt-0.5 size-4 shrink-0 text-brand" />
                    <span>
                        <span class="font-medium">{{ order.fulfillment }}</span>
                        <span
                            v-if="order.address"
                            class="block text-muted-foreground"
                            >{{ order.address }}</span
                        >
                    </span>
                </p>
                <p class="flex items-start gap-2">
                    <Wallet class="mt-0.5 size-4 shrink-0 text-brand" />
                    <span>
                        <span class="font-medium">{{
                            order.paymentMethod
                        }}</span>
                        <span
                            v-if="order.changeForCents"
                            class="block text-muted-foreground"
                        >
                            Troco para {{ formatCents(order.changeForCents) }}
                        </span>
                    </span>
                </p>
            </section>

            <section class="rounded-3xl bg-white p-5 shadow-soft">
                <h2 class="mb-2 font-semibold">Itens</h2>
                <ul class="flex flex-col divide-y divide-border">
                    <li
                        v-for="(item, position) in order.items"
                        :key="position"
                        class="flex justify-between gap-3 py-3 text-sm"
                    >
                        <span>
                            <span class="font-medium"
                                >{{ item.quantity }}× {{ item.name }}</span
                            >
                            <span
                                v-if="item.options.length"
                                class="block text-xs text-muted-foreground"
                                >{{ optionsText(item) }}</span
                            >
                            <span
                                v-if="item.notes"
                                class="block text-xs text-muted-foreground italic"
                                >Obs.: {{ item.notes }}</span
                            >
                        </span>
                        <span class="whitespace-nowrap">{{
                            formatCents(item.totalCents)
                        }}</span>
                    </li>
                </ul>
                <dl
                    class="mt-3 grid gap-1 rounded-2xl bg-brand-surface px-4 py-3 text-xs"
                >
                    <div class="flex justify-between">
                        <dt class="text-brand">Subtotal</dt>
                        <dd class="text-brand">
                            {{ formatCents(order.subtotalCents) }}
                        </dd>
                    </div>
                    <div
                        v-if="order.deliveryFeeCents > 0"
                        class="flex justify-between"
                    >
                        <dt class="text-brand">Entrega</dt>
                        <dd class="text-brand">
                            {{ formatCents(order.deliveryFeeCents) }}
                        </dd>
                    </div>
                    <div
                        v-if="order.discountCents > 0"
                        class="flex justify-between text-emerald-600"
                    >
                        <dt>
                            Desconto<template v-if="order.couponCode">
                                ({{ order.couponCode }})</template
                            >
                        </dt>
                        <dd>− {{ formatCents(order.discountCents) }}</dd>
                    </div>
                    <div class="mt-1 flex items-baseline justify-between">
                        <dt class="text-base font-semibold text-brand-ink">
                            Total
                        </dt>
                        <dd class="text-lg font-bold text-brand">
                            {{ formatCents(order.totalCents) }}
                        </dd>
                    </div>
                </dl>
            </section>

            <a
                v-if="store.whatsapp"
                :href="`https://wa.me/${store.whatsapp}?text=${encodeURIComponent(`Olá! Sobre o pedido nº ${order.number}`)}`"
                target="_blank"
                rel="noopener"
                class="flex h-12 items-center justify-center gap-2 rounded-2xl bg-emerald-500 font-medium text-white"
            >
                <MessageCircle class="size-5" />
                Falar com a loja
            </a>
            <Link
                :href="menuShow(store.slug)"
                class="flex h-14 items-center justify-center rounded-2xl bg-linear-to-r from-brand to-brand-accent font-medium text-white shadow-lg shadow-brand/25"
            >
                Voltar ao cardápio
            </Link>
        </main>
    </div>
</template>
