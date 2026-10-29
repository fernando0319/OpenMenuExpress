<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bike, Phone } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import NetworkDeliveryController from '@/actions/App/Http/Controllers/Orders/NetworkDeliveryController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';

export type NetworkPanel = {
    blockedReason: string | null;
    defaultFeeCents: number;
    nearby: number;
    radiusKm: number;
    delivery: {
        id: number;
        status:
            | 'searching'
            | 'accepted'
            | 'picked_up'
            | 'delivered'
            | 'cancelled';
        statusLabel: string;
        active: boolean;
        feeCents: number;
        searchingSince: string;
        cancelReason: string | null;
        courier: { name: string; phone: string; vehicle: string } | null;
    } | null;
};

/**
 * Calling a courier of the OpenMenuExpress network for a delivery order, and following the run.
 */
const props = defineProps<{
    orderId: number;
    network: NetworkPanel;
}>();

const fee = ref(centsToInput(props.network.defaultFeeCents));
const error = ref<string | null>(null);
const busy = ref(false);
const active = computed(() => props.network.delivery?.active === true);

// Seconds since the search started, for the "procurando há…" counter.
const now = ref(Date.now());
const timer = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(timer));

const waiting = computed(() => {
    const since = props.network.delivery?.searchingSince;

    if (!since) {
        return '';
    }

    const seconds = Math.max(
        0,
        Math.floor((now.value - new Date(since).getTime()) / 1000),
    );

    return seconds < 60 ? `${seconds}s` : `${Math.floor(seconds / 60)} min`;
});

function call(): void {
    const cents = parseMoney(fee.value);

    if (cents === null) {
        error.value = 'Informe o valor da corrida.';

        return;
    }

    busy.value = true;
    router.post(
        NetworkDeliveryController.store.url(props.orderId),
        { fee_cents: cents },
        {
            preserveScroll: true,
            onError: (errors) => {
                error.value = errors.fee ?? errors.fee_cents ?? null;
            },
            onSuccess: () => {
                error.value = null;
            },
            onFinish: () => {
                busy.value = false;
            },
        },
    );
}

function cancel(): void {
    if (!confirm('Cancelar a corrida? Não há cobrança antes da retirada.')) {
        return;
    }

    router.delete(NetworkDeliveryController.destroy.url(props.orderId), {
        preserveScroll: true,
        onError: (errors) => {
            error.value = errors.delivery ?? null;
        },
    });
}

function phoneLabel(digits: string): string {
    const local = digits.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : digits;
}
</script>

<template>
    <div class="mt-3 flex flex-col gap-2 border-t pt-3">
        <p class="flex items-center gap-2 text-xs text-muted-foreground">
            <Bike class="size-4" /> Rede de entregadores OpenMenuExpress
        </p>

        <template v-if="active && network.delivery">
            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="
                        network.delivery.status === 'searching'
                            ? 'outline'
                            : 'default'
                    "
                >
                    <span
                        v-if="network.delivery.status === 'searching'"
                        class="mr-1 inline-block size-2 animate-pulse rounded-full bg-amber-500"
                    />
                    {{ network.delivery.statusLabel }}
                </Badge>
                <span class="text-sm font-medium">{{
                    formatCents(network.delivery.feeCents)
                }}</span>
                <span
                    v-if="network.delivery.status === 'searching'"
                    class="text-xs text-muted-foreground"
                    >há {{ waiting }}</span
                >
            </div>
            <div v-if="network.delivery.courier" class="text-sm">
                <p class="font-medium">{{ network.delivery.courier.name }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ network.delivery.courier.vehicle }}
                </p>
                <a
                    :href="`tel:+${network.delivery.courier.phone}`"
                    class="inline-flex items-center gap-1 text-xs text-primary underline-offset-4 hover:underline"
                    ><Phone class="size-3" />
                    {{ phoneLabel(network.delivery.courier.phone) }}</a
                >
            </div>
            <Button
                v-if="network.delivery.status !== 'picked_up'"
                variant="outline"
                size="sm"
                class="w-fit"
                @click="cancel"
                >Cancelar corrida</Button
            >
        </template>

        <template v-else>
            <p
                v-if="
                    network.delivery && network.delivery.status === 'delivered'
                "
                class="text-sm"
            >
                Entregue por {{ network.delivery.courier?.name }} ·
                {{ formatCents(network.delivery.feeCents) }}
            </p>
            <template v-else-if="network.blockedReason === null">
                <p
                    v-if="network.delivery?.status === 'cancelled'"
                    class="text-xs text-muted-foreground"
                >
                    Corrida anterior cancelada<template
                        v-if="network.delivery.cancelReason"
                    >
                        ({{ network.delivery.cancelReason }})</template
                    >.
                </p>
                <div class="flex items-end gap-2">
                    <div class="grid gap-1">
                        <label
                            for="network-fee"
                            class="text-xs text-muted-foreground"
                            >Valor da corrida</label
                        >
                        <Input
                            id="network-fee"
                            v-model="fee"
                            inputmode="decimal"
                            class="w-28"
                        />
                    </div>
                    <Button size="sm" :disabled="busy" @click="call"
                        >Chamar entregador</Button
                    >
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        network.nearby > 0
                            ? `${network.nearby} entregador(es) online até ${network.radiusKm} km.`
                            : `Nenhum entregador online até ${network.radiusKm} km agora.`
                    }}
                </p>
            </template>
            <p v-else class="text-xs text-muted-foreground">
                {{ network.blockedReason }}
            </p>
        </template>

        <InputError :message="error ?? undefined" />
    </div>
</template>
