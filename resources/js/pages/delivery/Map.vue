<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import LiveMap from '@/components/maps/LiveMap.vue';
import { Badge } from '@/components/ui/badge';
import { map } from '@/routes/couriers';
import { show as orderShow } from '@/routes/orders';
import type { MapMarker, MapPoint, MapTiles } from '@/types/maps';

type CourierRow = {
    id: number;
    name: string;
    position: MapPoint | null;
    orders: { id: number; number: number; district: string | null }[];
};

const props = defineProps<{
    couriers: CourierRow[];
    unassigned: { id: number; number: number; district: string | null }[];
    mapTiles: MapTiles;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Entregadores', href: map() }],
    },
});

usePoll(10_000, { only: ['couriers', 'unassigned'] });

const markers = computed<MapMarker[]>(() =>
    props.couriers
        .filter((courier) => courier.position !== null)
        .map((courier) => ({
            id: courier.id,
            lat: courier.position!.lat,
            lng: courier.position!.lng,
            label: courier.name.split(' ')[0],
        })),
);

const time = new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' });
</script>

<template>
    <Head title="Entregadores" />

    <div class="flex flex-col gap-4 p-4">
        <Heading
            title="Entregadores"
            description="Quem está na rua agora. Atualiza sozinho a cada 10 segundos."
        />

        <div class="grid gap-4 lg:grid-cols-[1fr_20rem]">
            <LiveMap :tiles="mapTiles" :markers="markers" height="28rem" />

            <aside class="flex flex-col gap-3 text-sm">
                <p
                    v-if="couriers.length === 0"
                    class="rounded-xl border border-dashed p-4 text-muted-foreground"
                >
                    Nenhum entregador na rua. Atribua o entregador no pedido e
                    marque "Saiu para entrega".
                </p>
                <div
                    v-for="courier in couriers"
                    :key="courier.id"
                    class="rounded-xl border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-medium">{{ courier.name }}</span>
                        <Badge
                            :variant="courier.position ? 'default' : 'outline'"
                        >
                            {{
                                courier.position
                                    ? `às ${time.format(new Date(courier.position.at))}`
                                    : 'Sem localização'
                            }}
                        </Badge>
                    </div>
                    <ul class="mt-2 flex flex-wrap gap-1">
                        <li v-for="order in courier.orders" :key="order.id">
                            <Link
                                :href="orderShow(order.id)"
                                class="text-xs underline-offset-4 hover:underline"
                            >
                                nº {{ order.number
                                }}<template v-if="order.district">
                                    · {{ order.district }}</template
                                >
                            </Link>
                        </li>
                    </ul>
                </div>
                <div
                    v-if="unassigned.length > 0"
                    class="rounded-xl border border-amber-300 p-3 dark:border-amber-900"
                >
                    <p class="mb-1 font-medium">
                        Na rua sem entregador definido
                    </p>
                    <Link
                        v-for="order in unassigned"
                        :key="order.id"
                        :href="orderShow(order.id)"
                        class="mr-2 text-xs underline-offset-4 hover:underline"
                    >
                        nº {{ order.number }}
                    </Link>
                </div>
            </aside>
        </div>
    </div>
</template>
