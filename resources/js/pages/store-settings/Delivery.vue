<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import DeliveryController from '@/actions/App/Http/Controllers/StoreSettings/DeliveryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { edit } from '@/routes/store-settings/delivery';

type Zone = {
    id: number;
    name: string;
    fee: string;
    fee_cents: number;
    is_active: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Entrega e funcionamento', href: edit() }],
    },
});

const props = defineProps<{
    settings: {
        accepts_delivery: boolean;
        accepts_pickup: boolean;
        minimum_order: string;
        is_paused: boolean;
    };
    zones: Zone[];
}>();

const settingsForm = useForm({ ...props.settings });

function saveSettings(): void {
    settingsForm.put(DeliveryController.update.url(), { preserveScroll: true });
}

const zoneDialogOpen = ref(false);
const editingZone = ref<Zone | null>(null);
const zoneForm = useForm({ name: '', fee: '', is_active: true });

function openZone(zone: Zone | null): void {
    editingZone.value = zone;
    zoneForm.name = zone?.name ?? '';
    zoneForm.fee = zone?.fee ?? '';
    zoneForm.is_active = zone?.is_active ?? true;
    zoneForm.clearErrors();
    zoneDialogOpen.value = true;
}

function saveZone(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            zoneDialogOpen.value = false;
        },
    };

    if (editingZone.value) {
        zoneForm.put(
            DeliveryController.updateZone.url(editingZone.value.id),
            options,
        );
    } else {
        zoneForm.post(DeliveryController.storeZone.url(), options);
    }
}

function destroyZone(zone: Zone): void {
    if (!confirm(`Remover o bairro "${zone.name}"?`)) {
        return;
    }

    router.delete(DeliveryController.destroyZone.url(zone.id), {
        preserveScroll: true,
    });
}

function feeError(): string | undefined {
    return (zoneForm.errors as Record<string, string | undefined>).fee_cents;
}

function minimumError(): string | undefined {
    return (settingsForm.errors as Record<string, string | undefined>)
        .minimum_order_cents;
}
</script>

<template>
    <Head title="Entrega e funcionamento" />

    <div class="flex max-w-3xl flex-col gap-10 p-4">
        <form class="flex flex-col gap-6" @submit.prevent="saveSettings">
            <Heading
                title="Entrega e funcionamento"
                description="Como o cliente recebe o pedido e o valor mínimo para pedir."
            />

            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <Checkbox id="is_paused" v-model="settingsForm.is_paused" />
                    <Label for="is_paused"
                        >Loja fechada para pedidos agora (pausa manual)</Label
                    >
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="accepts_pickup"
                        v-model="settingsForm.accepts_pickup"
                    />
                    <Label for="accepts_pickup"
                        >Aceitar retirada no local</Label
                    >
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="accepts_delivery"
                        v-model="settingsForm.accepts_delivery"
                    />
                    <Label for="accepts_delivery"
                        >Aceitar entrega (nos bairros abaixo)</Label
                    >
                </div>
            </div>

            <div class="grid max-w-48 gap-2">
                <Label for="minimum_order">Pedido mínimo</Label>
                <Input
                    id="minimum_order"
                    v-model="settingsForm.minimum_order"
                    inputmode="decimal"
                    placeholder="0,00"
                />
                <p class="text-xs text-muted-foreground">
                    Sem contar a entrega.
                </p>
                <InputError :message="minimumError()" />
            </div>

            <div>
                <Button type="submit" :disabled="settingsForm.processing"
                    >Salvar</Button
                >
            </div>
        </form>

        <section class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="Bairros e taxas"
                    description="O cliente escolhe o bairro e a taxa é somada ao pedido."
                />
                <Button variant="outline" @click="openZone(null)">
                    <Plus />
                    Novo bairro
                </Button>
            </div>

            <div
                v-if="zones.length === 0"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nenhum bairro cadastrado. Sem bairros, o cardápio só oferece
                retirada.
            </div>

            <ul v-else class="flex flex-col divide-y rounded-xl border">
                <li
                    v-for="zone in zones"
                    :key="zone.id"
                    class="flex items-center gap-3 px-4 py-2 text-sm"
                >
                    <span class="flex-1 font-medium">{{ zone.name }}</span>
                    <Badge v-if="!zone.is_active" variant="secondary"
                        >Inativo</Badge
                    >
                    <span>{{
                        zone.fee_cents === 0
                            ? 'Grátis'
                            : formatCents(zone.fee_cents)
                    }}</span>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Editar ${zone.name}`"
                        @click="openZone(zone)"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Remover ${zone.name}`"
                        @click="destroyZone(zone)"
                    >
                        <Trash2 />
                    </Button>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="zoneDialogOpen">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="saveZone">
                <DialogHeader>
                    <DialogTitle>
                        {{ editingZone ? 'Editar bairro' : 'Novo bairro' }}
                    </DialogTitle>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="zone-name">Nome</Label>
                    <Input
                        id="zone-name"
                        v-model="zoneForm.name"
                        required
                        placeholder="Ex.: Centro"
                    />
                    <InputError :message="zoneForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="zone-fee">Taxa de entrega</Label>
                    <Input
                        id="zone-fee"
                        v-model="zoneForm.fee"
                        inputmode="decimal"
                        placeholder="0,00 para grátis"
                    />
                    <InputError :message="feeError()" />
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="zone-active" v-model="zoneForm.is_active" />
                    <Label for="zone-active">Atender este bairro</Label>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="zoneForm.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
