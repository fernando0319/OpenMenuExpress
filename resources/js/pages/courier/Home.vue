<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import {
    Banknote,
    BellRing,
    Clock,
    MapPin,
    MessageCircle,
    Navigation,
    Phone,
    Store,
} from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import CourierPushController from '@/actions/App/Http/Controllers/Courier/CourierPushController';
import CourierAppController from '@/actions/App/Http/Controllers/Courier/CourierAppController';
import CourierRunController from '@/actions/App/Http/Controllers/Courier/CourierRunController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLocationSharing } from '@/composables/useLocationSharing';
import { formatCents } from '@/lib/money';
import { enablePush, pushState } from '@/lib/push';
import type { PushState } from '@/lib/push';
import { playNewOrderChime, unlockSound } from '@/lib/sound';

type Collect = {
    amountCents: number;
    method: string;
    changeForCents: number | null;
};

type Offer = {
    id: number;
    storeName: string;
    storeDistrict: string | null;
    distanceKm: number;
    dropDistrict: string | null;
    dropCity: string | null;
    routeKm: number | null;
    courierCents: number;
    collect: Collect | null;
};

type Active = {
    id: number;
    status: 'accepted' | 'picked_up';
    statusLabel: string;
    courierCents: number;
    store: {
        name: string;
        phone: string | null;
        address: string;
        district: string | null;
        complement: string | null;
        lat: number;
        lng: number;
    };
    order: {
        number: number;
        customerName: string;
        customerPhone: string | null;
        address: string;
        complement: string | null;
        district: string | null;
        city: string | null;
        reference: string | null;
        mapQuery: string;
        notes: string | null;
        collect: Collect | null;
    };
};

const props = defineProps<{
    courier: {
        name: string;
        status: 'pending' | 'approved' | 'rejected' | 'blocked';
        statusLabel: string;
        statusReason: string | null;
        isOnline: boolean;
        vehicle: string;
    };
    networkEnabled: boolean;
    pushKey: string | null;
    active: Active | null;
    offers: Offer[];
    today: { count: number; amountCents: number };
    week: { count: number; amountCents: number };
}>();

const approved = computed(() => props.courier.status === 'approved');
const errors = ref<Record<string, string>>({});
const busy = ref(false);

// The position goes to the server while online (offers nearby) and during a run (customer tracking).
const location = useLocationSharing(
    CourierAppController.location.url(),
    15_000,
    60_000,
);
const wantsLocation = computed(
    () => approved.value && (props.courier.isOnline || props.active !== null),
);

watch(
    wantsLocation,
    (wanted) => {
        if (wanted && !location.sharing.value) {
            location.start();
        }

        if (!wanted && location.sharing.value) {
            location.stop();
        }
    },
    { immediate: true },
);

// Fresh offers every few seconds while working; the pending screen checks for approval now and then.
const fast = usePoll(
    5_000,
    { only: ['offers', 'active', 'today', 'week', 'courier'] },
    { autoStart: false },
);
const slow = usePoll(30_000, { only: ['courier'] }, { autoStart: false });

watch(
    wantsLocation,
    (working) => {
        if (working) {
            slow.stop();
            fast.start();
        } else {
            fast.stop();
            slow.start();
        }
    },
    { immediate: true },
);

// With the app open a new offer rings and vibrates; in the background the push notification does it.
let knownOffers = new Set<number>();

onMounted(() => {
    knownOffers = new Set(props.offers.map((offer) => offer.id));
    push.value = pushState();

    // Already allowed: make sure this phone is still registered (the browser may renew it).
    if (push.value === 'granted' && approved.value) {
        void turnOnPush();
    }
});

watch(
    () => props.offers.map((offer) => offer.id),
    (ids) => {
        const fresh = ids.filter((id) => !knownOffers.has(id));
        knownOffers = new Set(ids);

        if (fresh.length > 0 && document.visibilityState === 'visible') {
            playNewOrderChime();
            navigator.vibrate?.([300, 150, 300]);
        }
    },
);

const push = ref<PushState>('default');

async function turnOnPush(): Promise<void> {
    if (props.pushKey === null) {
        return;
    }

    try {
        push.value = await enablePush(
            props.pushKey,
            CourierPushController.store.url(),
        );
    } catch {
        push.value = pushState();
    }
}

function post(
    url: string,
    data: Record<string, string | number | boolean> = {},
): void {
    busy.value = true;
    router.post(url, data, {
        preserveScroll: true,
        onError: (failure) => {
            errors.value = failure;
        },
        onSuccess: () => {
            errors.value = {};
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

async function toggleOnline(): Promise<void> {
    const goingOnline = !props.courier.isOnline;

    if (goingOnline) {
        await unlockSound();
        await turnOnPush();
    }

    post(CourierAppController.online.url(), { online: goingOnline });
}

function accept(offer: Offer): void {
    post(CourierRunController.accept.url(offer.id));
}

function decline(offer: Offer): void {
    post(CourierRunController.decline.url(offer.id));
}

function giveUp(active: Active): void {
    if (
        confirm(
            'Devolver a corrida? Ela volta para os outros entregadores e não é oferecida a você de novo.',
        )
    ) {
        post(CourierRunController.giveUp.url(active.id));
    }
}

function pickUp(active: Active): void {
    post(CourierRunController.pickUp.url(active.id));
}

function complete(active: Active): void {
    if (confirm(`Confirmar a entrega do pedido nº ${active.order.number}?`)) {
        post(CourierRunController.complete.url(active.id));
    }
}

function directionsTo(destination: string): string {
    return `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(destination)}`;
}

function phoneLabel(digits: string): string {
    const local = digits.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : digits;
}

function collectText(collect: Collect): string {
    const change =
        collect.changeForCents && collect.changeForCents > collect.amountCents
            ? ` · levar troco para ${formatCents(collect.changeForCents)} (${formatCents(collect.changeForCents - collect.amountCents)})`
            : '';

    return `Cobrar ${formatCents(collect.amountCents)} (${collect.method})${change}`;
}

const firstName = computed(() => props.courier.name.split(' ')[0]);
</script>

<template>
    <Head title="Corridas" />

    <!-- Waiting for approval, refused or blocked -->
    <section
        v-if="!approved"
        class="flex flex-col gap-3 rounded-2xl border bg-background p-5"
    >
        <Badge
            :variant="
                courier.status === 'pending' ? 'secondary' : 'destructive'
            "
            class="w-fit"
            >{{ courier.statusLabel }}</Badge
        >
        <template v-if="courier.status === 'pending'">
            <h1 class="text-lg font-semibold">
                Recebemos seu cadastro, {{ firstName }}!
            </h1>
            <p class="text-sm text-muted-foreground">
                Estamos conferindo seus dados e o documento. Assim que for
                aprovado, esta tela libera o botão para ficar online e receber
                corridas. Pode deixar o app aberto ou voltar mais tarde.
            </p>
        </template>
        <template v-else>
            <h1 class="text-lg font-semibold">
                {{
                    courier.status === 'rejected'
                        ? 'Seu cadastro não foi aprovado'
                        : 'Seu acesso à rede está bloqueado'
                }}
            </h1>
            <p v-if="courier.statusReason" class="text-sm">
                Motivo: {{ courier.statusReason }}
            </p>
            <p class="text-sm text-muted-foreground">
                Se acha que foi um engano, fale com o suporte do
                OpenMenuExpress.
            </p>
        </template>
    </section>

    <template v-else>
        <!-- Online switch -->
        <section
            class="flex items-center gap-4 rounded-2xl border p-4 transition"
            :class="
                courier.isOnline
                    ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950'
                    : 'bg-background'
            "
        >
            <div class="flex-1">
                <p class="font-semibold">
                    {{
                        courier.isOnline
                            ? 'Você está online'
                            : 'Você está offline'
                    }}
                </p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="!networkEnabled"
                        >A rede está pausada no momento.</template
                    >
                    <template
                        v-else-if="courier.isOnline && location.error.value"
                        >{{ location.error.value }}</template
                    >
                    <template v-else-if="courier.isOnline"
                        >Recebendo corridas perto de você. Mantenha o app aberto
                        e a tela ligada.</template
                    >
                    <template v-else
                        >Fique online para receber corridas das lojas por
                        perto.</template
                    >
                </p>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="courier.isOnline"
                aria-label="Ficar online"
                :disabled="busy || (!networkEnabled && !courier.isOnline)"
                class="relative h-8 w-14 shrink-0 rounded-full transition disabled:opacity-50"
                :class="
                    courier.isOnline
                        ? 'bg-emerald-500'
                        : 'bg-muted-foreground/30'
                "
                @click="toggleOnline"
            >
                <span
                    class="absolute top-1 left-1 size-6 rounded-full bg-white shadow transition"
                    :class="courier.isOnline ? 'translate-x-6' : ''"
                />
            </button>
        </section>
        <InputError
            :message="errors.online ?? errors.courier ?? errors.delivery"
        />

        <!-- Notifications: how runs reach the phone with the screen off -->
        <section
            v-if="pushKey && push !== 'granted'"
            class="flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            <BellRing class="mt-0.5 size-5 shrink-0" />
            <div class="flex flex-1 flex-col gap-2">
                <template v-if="push === 'default'">
                    <p>
                        Ative as notificações para receber corridas mesmo com a
                        tela desligada ou o app fechado.
                    </p>
                    <Button size="sm" class="w-fit" @click="turnOnPush"
                        >Ativar notificações</Button
                    >
                </template>
                <p v-else-if="push === 'denied'">
                    As notificações estão bloqueadas. Libere em
                    <strong
                        >Configurações do celular → Apps → Chrome (ou o app
                        instalado) → Notificações</strong
                    >, depois volte aqui.
                </p>
                <p v-else>
                    Este navegador não recebe avisos com a tela desligada. No
                    Android use o Chrome; no iPhone, instale o app na tela de
                    início e abra por ele.
                </p>
            </div>
        </section>

        <!-- Earnings -->
        <section class="grid grid-cols-2 gap-3">
            <div class="rounded-2xl border bg-background p-4">
                <p class="text-xs text-muted-foreground">Hoje</p>
                <p class="text-xl font-semibold">
                    {{ formatCents(today.amountCents) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ today.count }} corrida(s)
                </p>
            </div>
            <div class="rounded-2xl border bg-background p-4">
                <p class="text-xs text-muted-foreground">Nesta semana</p>
                <p class="text-xl font-semibold">
                    {{ formatCents(week.amountCents) }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ week.count }} corrida(s)
                </p>
            </div>
        </section>

        <!-- The run in progress -->
        <section
            v-if="active"
            class="flex flex-col gap-4 rounded-2xl border-2 border-primary bg-background p-4"
        >
            <div class="flex items-center justify-between gap-2">
                <Badge>{{ active.statusLabel }}</Badge>
                <span class="text-sm font-semibold"
                    >Você recebe {{ formatCents(active.courierCents) }}</span
                >
            </div>

            <!-- Step 1: to the store -->
            <div
                class="flex flex-col gap-2"
                :class="active.status === 'picked_up' ? 'opacity-60' : ''"
            >
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <Store class="size-4" /> Retirar na loja
                </p>
                <p class="text-base font-medium">{{ active.store.name }}</p>
                <p class="text-sm">
                    {{ active.store.address }}
                    <template v-if="active.store.district">
                        · {{ active.store.district }}</template
                    >
                </p>
                <p
                    v-if="active.store.complement"
                    class="text-xs text-muted-foreground"
                >
                    {{ active.store.complement }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Pedido nº {{ active.order.number }} ·
                    {{ active.order.customerName }}
                </p>
                <div
                    v-if="active.status === 'accepted'"
                    class="flex flex-wrap gap-2"
                >
                    <Button as-child variant="outline" size="sm">
                        <a
                            :href="
                                directionsTo(
                                    `${active.store.lat},${active.store.lng}`,
                                )
                            "
                            target="_blank"
                            rel="noopener"
                            ><Navigation /> Ir até a loja</a
                        >
                    </Button>
                    <Button
                        v-if="active.store.phone"
                        as-child
                        variant="outline"
                        size="sm"
                    >
                        <a
                            :href="`https://wa.me/${active.store.phone}`"
                            target="_blank"
                            rel="noopener"
                            ><MessageCircle /> Loja</a
                        >
                    </Button>
                </div>
            </div>

            <!-- Step 2: to the customer -->
            <div
                v-if="active.status === 'picked_up'"
                class="flex flex-col gap-2 border-t pt-4"
            >
                <p class="flex items-center gap-2 text-sm font-semibold">
                    <MapPin class="size-4" /> Entregar para
                    {{ active.order.customerName }}
                </p>
                <p class="text-base font-medium">{{ active.order.address }}</p>
                <p class="text-sm">
                    <template v-if="active.order.complement"
                        >{{ active.order.complement }} ·
                    </template>
                    {{ active.order.district }}
                </p>
                <p
                    v-if="active.order.reference"
                    class="text-xs text-muted-foreground"
                >
                    Referência: {{ active.order.reference }}
                </p>
                <p
                    v-if="active.order.notes"
                    class="text-xs text-muted-foreground"
                >
                    Observação: {{ active.order.notes }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button as-child variant="outline" size="sm">
                        <a
                            :href="directionsTo(active.order.mapQuery)"
                            target="_blank"
                            rel="noopener"
                            ><Navigation /> Rota</a
                        >
                    </Button>
                    <template v-if="active.order.customerPhone">
                        <Button as-child variant="outline" size="sm">
                            <a :href="`tel:+${active.order.customerPhone}`"
                                ><Phone />
                                {{ phoneLabel(active.order.customerPhone) }}</a
                            >
                        </Button>
                        <Button as-child variant="outline" size="sm">
                            <a
                                :href="`https://wa.me/${active.order.customerPhone}`"
                                target="_blank"
                                rel="noopener"
                                ><MessageCircle /> WhatsApp</a
                            >
                        </Button>
                    </template>
                </div>
            </div>

            <div
                v-if="active.order.collect"
                class="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100"
            >
                <Banknote class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-medium">
                        {{ collectText(active.order.collect) }}
                    </p>
                    <p class="text-xs">
                        O valor do pedido é acertado com a loja, não com o Menu
                        Express.
                    </p>
                </div>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                Pedido já pago: não cobre nada do cliente.
            </p>

            <div class="flex flex-col gap-2">
                <Button
                    v-if="active.status === 'accepted'"
                    size="lg"
                    :disabled="busy"
                    @click="pickUp(active)"
                    >Peguei o pedido na loja</Button
                >
                <Button
                    v-else
                    size="lg"
                    :disabled="busy"
                    @click="complete(active)"
                    >Entreguei ao cliente</Button
                >
                <Button
                    v-if="active.status === 'accepted'"
                    variant="ghost"
                    size="sm"
                    :disabled="busy"
                    @click="giveUp(active)"
                    >Não posso fazer esta corrida</Button
                >
            </div>
        </section>

        <!-- Runs on offer -->
        <template v-else-if="courier.isOnline">
            <div
                v-if="offers.length === 0"
                class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-background p-8 text-center"
            >
                <span class="relative flex size-4">
                    <span
                        class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"
                    />
                    <span
                        class="relative inline-flex size-4 rounded-full bg-emerald-500"
                    />
                </span>
                <p class="text-sm font-medium">
                    Procurando corridas perto de você…
                </p>
                <p class="text-xs text-muted-foreground">
                    Quando uma loja chamar, o celular toca e vibra.
                </p>
            </div>

            <article
                v-for="offer in offers"
                :key="offer.id"
                class="flex flex-col gap-3 rounded-2xl border bg-background p-4 shadow-xs"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold">{{ offer.storeName }}</p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="offer.storeDistrict"
                                >{{ offer.storeDistrict }} ·
                            </template>
                            a {{ offer.distanceKm.toLocaleString('pt-BR') }} km
                            de você
                        </p>
                    </div>
                    <p class="text-lg font-bold text-emerald-600">
                        {{ formatCents(offer.courierCents) }}
                    </p>
                </div>
                <p class="flex items-center gap-2 text-sm">
                    <MapPin class="size-4 text-muted-foreground" />
                    Entrega em {{ offer.dropDistrict ?? 'endereço do cliente' }}
                    <template v-if="offer.dropCity">
                        · {{ offer.dropCity }}</template
                    >
                    <template v-if="offer.routeKm">
                        ({{ offer.routeKm.toLocaleString('pt-BR') }} km da
                        loja)</template
                    >
                </p>
                <p
                    v-if="offer.collect"
                    class="flex items-center gap-2 text-xs text-amber-700 dark:text-amber-300"
                >
                    <Banknote class="size-4" /> Cobrar
                    {{ formatCents(offer.collect.amountCents) }} na entrega ({{
                        offer.collect.method
                    }})
                </p>
                <div class="grid grid-cols-3 gap-2">
                    <Button
                        variant="outline"
                        :disabled="busy"
                        @click="decline(offer)"
                        >Recusar</Button
                    >
                    <Button
                        class="col-span-2"
                        :disabled="busy"
                        @click="accept(offer)"
                        >Aceitar corrida</Button
                    >
                </div>
            </article>
        </template>

        <p
            v-else
            class="flex items-center gap-2 rounded-2xl border bg-background p-4 text-sm text-muted-foreground"
        >
            <Clock class="size-4" /> Fique online para ver as corridas
            disponíveis.
        </p>
    </template>
</template>
