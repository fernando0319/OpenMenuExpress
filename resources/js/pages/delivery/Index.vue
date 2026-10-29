<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { MapPin, MessageCircle, Navigation, Phone } from '@lucide/vue';
import { computed, watch } from 'vue';
import MyDeliveriesController from '@/actions/App/Http/Controllers/Delivery/MyDeliveriesController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLocationSharing } from '@/composables/useLocationSharing';
import { formatCents } from '@/lib/money';
import { index } from '@/routes/deliveries';

type Delivery = {
    id: number;
    number: number;
    status: string;
    statusLabel: string;
    customerName: string;
    customerPhone: string | null;
    address: string;
    complement: string | null;
    district: string | null;
    reference: string | null;
    mapQuery: string;
    totalCents: number;
    paymentMethod: string;
    paid: boolean;
    changeForCents: number | null;
    notes: string | null;
};

const props = defineProps<{
    orders: Delivery[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Minhas entregas', href: index() }],
    },
});

usePoll(20_000, { only: ['orders'] });

const location = useLocationSharing(MyDeliveriesController.location.url());
const onTheWay = computed(() =>
    props.orders.filter((order) => order.status === 'out_for_delivery'),
);

// Starts sharing as soon as something is on the way, and stops when the last one is delivered.
watch(
    () => onTheWay.value.length,
    (count, previous) => {
        if (count > 0 && !location.sharing.value && (previous ?? 0) === 0) {
            location.start();
        }

        if (count === 0 && location.sharing.value) {
            location.stop();
        }
    },
    { immediate: true },
);

const time = new Intl.DateTimeFormat('pt-BR', { timeStyle: 'medium' });

function depart(order: Delivery): void {
    router.post(
        MyDeliveriesController.depart.url(order.id),
        {},
        { preserveScroll: true },
    );
}

function complete(order: Delivery): void {
    if (confirm(`Confirmar a entrega do pedido nº ${order.number}?`)) {
        router.post(
            MyDeliveriesController.complete.url(order.id),
            {},
            { preserveScroll: true },
        );
    }
}

function phoneLabel(digits: string): string {
    const local = digits.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : digits;
}
</script>

<template>
    <Head title="Minhas entregas" />

    <div class="flex max-w-xl flex-col gap-4 p-4">
        <Heading
            title="Minhas entregas"
            :description="
                orders.length === 0
                    ? 'Nenhuma entrega com você agora.'
                    : `${orders.length} entrega(s)`
            "
        />

        <section
            class="flex flex-col gap-2 rounded-xl border p-4 text-sm"
            :class="
                location.sharing.value
                    ? 'border-emerald-500 bg-emerald-50 dark:bg-emerald-950'
                    : ''
            "
        >
            <div class="flex items-center gap-3">
                <Navigation
                    class="size-5"
                    :class="
                        location.sharing.value
                            ? 'text-emerald-600'
                            : 'text-muted-foreground'
                    "
                />
                <div class="flex-1">
                    <p class="font-medium">
                        {{
                            location.sharing.value
                                ? 'Compartilhando sua localização'
                                : 'Localização desligada'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="location.lastSentAt.value"
                            >Última atualização às
                            {{
                                time.format(location.lastSentAt.value)
                            }}</template
                        >
                        <template v-else-if="location.sharing.value"
                            >Aguardando o GPS…</template
                        >
                        <template v-else
                            >Liga sozinha quando você sai para
                            entregar.</template
                        >
                    </p>
                </div>
                <Button
                    size="sm"
                    :variant="location.sharing.value ? 'outline' : 'default'"
                    @click="
                        location.sharing.value
                            ? location.stop()
                            : location.start()
                    "
                >
                    {{ location.sharing.value ? 'Parar' : 'Ligar' }}
                </Button>
            </div>
            <p v-if="location.sharing.value" class="text-xs">
                Mantenha esta tela aberta e o celular desbloqueado durante a
                entrega: com a tela apagada o navegador para de enviar a
                localização.
            </p>
            <p
                v-if="location.error.value"
                class="text-xs font-medium text-destructive"
            >
                {{ location.error.value }}
            </p>
        </section>

        <article
            v-for="order in orders"
            :key="order.id"
            class="flex flex-col gap-3 rounded-xl border p-4 text-sm"
        >
            <div class="flex items-center gap-2">
                <span class="text-base font-semibold"
                    >Pedido nº {{ order.number }}</span
                >
                <Badge
                    class="ml-auto"
                    :variant="
                        order.status === 'out_for_delivery'
                            ? 'default'
                            : 'secondary'
                    "
                    >{{ order.statusLabel }}</Badge
                >
            </div>

            <div class="flex gap-2">
                <MapPin class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div>
                    <p class="font-medium">
                        {{ order.address
                        }}<template v-if="order.complement">
                            — {{ order.complement }}</template
                        >
                    </p>
                    <p v-if="order.district">{{ order.district }}</p>
                    <p v-if="order.reference" class="text-muted-foreground">
                        Ref.: {{ order.reference }}
                    </p>
                </div>
            </div>

            <p>
                <strong>{{ order.customerName }}</strong>
                <template v-if="order.customerPhone">
                    · {{ phoneLabel(order.customerPhone) }}</template
                >
            </p>

            <p class="rounded-lg bg-muted/60 p-2">
                <template v-if="order.paid"
                    >Já pago ({{ order.paymentMethod }}).</template
                >
                <template v-else>
                    Cobrar
                    <strong>{{ formatCents(order.totalCents) }}</strong> em
                    {{ order.paymentMethod }}.
                    <template v-if="order.changeForCents">
                        Levar troco para
                        {{ formatCents(order.changeForCents) }} ({{
                            formatCents(
                                order.changeForCents - order.totalCents,
                            )
                        }}).
                    </template>
                </template>
            </p>
            <p v-if="order.notes" class="text-amber-600">
                Obs.: {{ order.notes }}
            </p>

            <div class="grid grid-cols-3 gap-2">
                <Button as-child variant="outline" size="sm">
                    <a
                        :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(order.mapQuery)}`"
                        target="_blank"
                        rel="noopener"
                    >
                        <Navigation />
                        Rota
                    </a>
                </Button>
                <Button
                    v-if="order.customerPhone"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <a :href="`tel:+${order.customerPhone}`"><Phone /> Ligar</a>
                </Button>
                <Button
                    v-if="order.customerPhone"
                    as-child
                    variant="outline"
                    size="sm"
                >
                    <a
                        :href="`https://wa.me/${order.customerPhone}`"
                        target="_blank"
                        rel="noopener"
                        ><MessageCircle /> WhatsApp</a
                    >
                </Button>
            </div>

            <Button
                v-if="order.status !== 'out_for_delivery'"
                size="lg"
                @click="depart(order)"
                >Saí para entrega</Button
            >
            <Button v-else size="lg" @click="complete(order)">Entregue</Button>
        </article>
    </div>
</template>
