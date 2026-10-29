<script setup lang="ts">
import { Bike, Navigation } from '@lucide/vue';
import { ref, watch } from 'vue';
import type { NetworkPanel } from '@/components/orders/NetworkDeliveryPanel.vue';
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
import { centsToInput, parseMoney } from '@/lib/money';

/**
 * Asked when an order leaves for delivery without a courier: someone of the team, or a
 * courier of the OpenMenuExpress network (who then comes to pick it up).
 */
const props = defineProps<{
    orderNumber: number | null;
    couriers: { id: number; name: string; role: string }[];
    network?: NetworkPanel | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    choose: [courierId: number | null];
    callNetwork: [feeCents: number];
}>();

const fee = ref('');
const feeError = ref<string | null>(null);

watch(open, (opened) => {
    if (opened && props.network) {
        fee.value = centsToInput(props.network.defaultFeeCents);
        feeError.value = null;
    }
});

function choose(courierId: number | null): void {
    open.value = false;
    emit('choose', courierId);
}

function callNetwork(): void {
    const cents = parseMoney(fee.value);

    if (cents === null) {
        feeError.value = 'Informe o valor da corrida.';

        return;
    }

    open.value = false;
    emit('callNetwork', cents);
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle
                    >Quem vai entregar o pedido nº
                    {{ orderNumber }}?</DialogTitle
                >
                <DialogDescription>
                    O entregador vê o pedido no celular e o cliente acompanha
                    pelo mapa.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="network && network.blockedReason === null"
                class="flex flex-col gap-2 rounded-xl border border-primary/40 bg-primary/5 p-3"
            >
                <p class="flex items-center gap-2 text-sm font-medium">
                    <Navigation class="size-4" /> Entregador da rede Menu
                    Express
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        network.nearby > 0
                            ? `${network.nearby} entregador(es) online até ${network.radiusKm} km da loja.`
                            : `Nenhum entregador online até ${network.radiusKm} km agora. Quem ficar online por perto recebe o chamado.`
                    }}
                    O pedido sai para entrega quando ele retirar na loja.
                </p>
                <div class="flex items-end gap-2">
                    <div class="grid gap-1">
                        <label
                            for="picker-network-fee"
                            class="text-xs text-muted-foreground"
                            >Valor da corrida</label
                        >
                        <Input
                            id="picker-network-fee"
                            v-model="fee"
                            inputmode="decimal"
                            class="w-28"
                        />
                    </div>
                    <Button class="flex-1" @click="callNetwork"
                        >Chamar entregador</Button
                    >
                </div>
                <p v-if="feeError" class="text-xs text-destructive">
                    {{ feeError }}
                </p>
            </div>
            <p
                v-else-if="network?.blockedReason"
                class="text-xs text-muted-foreground"
            >
                Rede de entregadores: {{ network.blockedReason }}
            </p>

            <div v-if="couriers.length > 0" class="flex flex-col gap-2">
                <p
                    v-if="network && network.blockedReason === null"
                    class="text-xs font-medium text-muted-foreground"
                >
                    Ou alguém da equipe:
                </p>
                <Button
                    v-for="courier in couriers"
                    :key="courier.id"
                    variant="outline"
                    class="h-auto justify-start py-3"
                    @click="choose(courier.id)"
                >
                    <Bike />
                    <span class="flex flex-col items-start">
                        <span>{{ courier.name }}</span>
                        <span
                            class="text-xs font-normal text-muted-foreground"
                            >{{ courier.role }}</span
                        >
                    </span>
                </Button>
            </div>

            <DialogFooter>
                <Button variant="ghost" class="w-full" @click="choose(null)"
                    >Sair sem definir entregador</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
