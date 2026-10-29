<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Copy, LocateFixed } from '@lucide/vue';
import { computed, ref } from 'vue';
import CourierNetworkController from '@/actions/App/Http/Controllers/StoreSettings/CourierNetworkController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import LocationPicker from '@/components/maps/LocationPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';
import { show as orderShow } from '@/routes/orders';
import { edit } from '@/routes/store-settings/courier-network';
import type { MapTiles } from '@/types/maps';

type Bill = {
    id: number;
    period: string;
    cycle: string;
    count: number;
    amountCents: number;
    dueAt: string;
    status: 'open' | 'paid' | 'waived';
    statusLabel: string;
    overdue: boolean;
};

type Run = {
    id: number;
    orderId: number;
    orderNumber: number;
    courierName: string | null;
    status: string;
    statusLabel: string;
    feeCents: number;
    createdAt: string | null;
    charged: boolean;
};

const props = defineProps<{
    available: boolean;
    settings: {
        enabled: boolean;
        feeCents: number | null;
        cycle: string;
        latitude: number | null;
        longitude: number | null;
    };
    address: string;
    cycles: { value: string; label: string; description: string }[];
    minFeeCents: number;
    pix: { key: string | null; holder: string | null };
    contactWhatsapp: string | null;
    unbilled: { count: number; amountCents: number };
    overdue: boolean;
    bills: Bill[];
    recent: Run[];
    mapTiles: MapTiles;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Rede de entregadores', href: edit() }],
    },
});

const point = ref<{ lat: number; lng: number } | null>(
    props.settings.latitude !== null && props.settings.longitude !== null
        ? { lat: props.settings.latitude, lng: props.settings.longitude }
        : null,
);
const fee = ref(
    props.settings.feeCents !== null
        ? centsToInput(props.settings.feeCents)
        : '',
);

const form = useForm({
    enabled: props.settings.enabled,
    fee_cents: props.settings.feeCents as number | null,
    cycle: props.settings.cycle,
    latitude: props.settings.latitude,
    longitude: props.settings.longitude,
});

const feeInvalid = computed(
    () => fee.value.trim() !== '' && parseMoney(fee.value) === null,
);

function save(): void {
    form.transform((data) => ({
        ...data,
        fee_cents: fee.value.trim() === '' ? null : parseMoney(fee.value),
        latitude: point.value?.lat ?? null,
        longitude: point.value?.lng ?? null,
    })).put(CourierNetworkController.update.url(), { preserveScroll: true });
}

const locating = ref(false);
const locateError = ref<string | null>(null);

function useDeviceLocation(): void {
    if (!('geolocation' in navigator)) {
        locateError.value =
            'Este aparelho não informa a localização. Toque no mapa.';

        return;
    }

    locating.value = true;
    locateError.value = null;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            point.value = {
                lat: Number(position.coords.latitude.toFixed(7)),
                lng: Number(position.coords.longitude.toFixed(7)),
            };
            locating.value = false;
        },
        () => {
            locateError.value =
                'Não foi possível obter a localização. Toque no mapa onde fica a loja.';
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 15_000 },
    );
}

const copied = ref(false);

async function copyPix(): Promise<void> {
    if (props.pix.key) {
        await navigator.clipboard.writeText(props.pix.key);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    }
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });
const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Rede de entregadores" />

    <div class="flex max-w-3xl flex-col gap-10 p-4">
        <Heading
            title="Rede de entregadores"
            description="Chame um entregador cadastrado no OpenMenuExpress quando não tiver o seu. Você define o valor da corrida e paga só as entregas feitas."
        />

        <p
            v-if="!available"
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
        >
            A rede de entregadores ainda não está disponível. Você já pode
            deixar tudo configurado.
        </p>

        <div
            v-if="overdue"
            class="flex items-start gap-3 rounded-xl border border-destructive/50 bg-destructive/10 p-4 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0 text-destructive" />
            <p>
                Há uma fatura de entregas vencida. Enquanto ela não for paga,
                não é possível chamar entregadores da rede.
            </p>
        </div>

        <form class="flex flex-col gap-6" @submit.prevent="save">
            <div class="flex items-center gap-2">
                <Checkbox id="enabled" v-model="form.enabled" />
                <Label for="enabled">Usar a rede de entregadores</Label>
            </div>

            <div class="grid max-w-56 gap-2">
                <Label for="fee">Valor padrão da corrida</Label>
                <Input
                    id="fee"
                    v-model="fee"
                    inputmode="decimal"
                    placeholder="0,00"
                />
                <p class="text-xs text-muted-foreground">
                    Sugerido ao chamar; dá para mudar em cada pedido. Mínimo
                    {{ formatCents(minFeeCents) }}. Sem valor padrão, usamos a
                    taxa de entrega do pedido.
                </p>
                <InputError
                    :message="
                        feeInvalid ? 'Valor inválido.' : form.errors.fee_cents
                    "
                />
            </div>

            <div class="flex flex-col gap-2">
                <Label>Fatura das corridas</Label>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label
                        v-for="cycle in cycles"
                        :key="cycle.value"
                        class="flex cursor-pointer flex-col gap-1 rounded-xl border p-3"
                        :class="
                            form.cycle === cycle.value
                                ? 'border-primary bg-primary/5'
                                : ''
                        "
                    >
                        <span
                            class="flex items-center gap-2 text-sm font-medium"
                        >
                            <input
                                v-model="form.cycle"
                                type="radio"
                                name="cycle"
                                :value="cycle.value"
                                class="accent-primary"
                            />
                            {{ cycle.label }}
                        </span>
                        <span class="text-xs text-muted-foreground">{{
                            cycle.description
                        }}</span>
                    </label>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <Label>Onde o entregador retira os pedidos</Label>
                        <p class="text-xs text-muted-foreground">
                            Toque no mapa sobre a porta da loja.
                            <template v-if="address"
                                >Endereço cadastrado: {{ address }}.</template
                            >
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        :disabled="locating"
                        @click="useDeviceLocation"
                    >
                        <LocateFixed />
                        {{ locating ? 'Localizando…' : 'Estou na loja agora' }}
                    </Button>
                </div>
                <LocationPicker v-model="point" :tiles="mapTiles" />
                <InputError :message="locateError ?? form.errors.latitude" />
            </div>

            <div>
                <Button type="submit" :disabled="form.processing || feeInvalid"
                    >Salvar</Button
                >
            </div>
        </form>

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Faturas"
                description="Só entram as corridas entregues. Cancelar antes da retirada não custa nada."
            />

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border p-4">
                    <p class="text-xs text-muted-foreground">
                        Para a próxima fatura
                    </p>
                    <p class="text-xl font-semibold">
                        {{ formatCents(unbilled.amountCents) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ unbilled.count }} corrida(s) entregues
                    </p>
                </div>
                <div class="flex flex-col gap-1 rounded-xl border p-4 text-sm">
                    <p class="text-xs text-muted-foreground">Pague por PIX</p>
                    <template v-if="pix.key">
                        <button
                            type="button"
                            class="flex items-center gap-2 text-left font-medium break-all"
                            @click="copyPix"
                        >
                            {{ pix.key }}
                            <Copy
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                        </button>
                        <p
                            v-if="pix.holder"
                            class="text-xs text-muted-foreground"
                        >
                            {{ pix.holder }}
                        </p>
                        <p v-if="copied" class="text-xs text-emerald-600">
                            Chave copiada.
                        </p>
                    </template>
                    <p v-else class="text-muted-foreground">
                        Chave informada na fatura.
                    </p>
                    <a
                        v-if="contactWhatsapp"
                        :href="`https://wa.me/${contactWhatsapp}`"
                        target="_blank"
                        rel="noopener"
                        class="text-xs underline"
                        >Enviar comprovante pelo WhatsApp</a
                    >
                </div>
            </div>

            <p
                v-if="bills.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nenhuma fatura ainda.
            </p>
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Período</th>
                            <th class="px-4 py-2 text-right font-medium">
                                Corridas
                            </th>
                            <th class="px-4 py-2 text-right font-medium">
                                Valor
                            </th>
                            <th class="px-4 py-2 font-medium">Vencimento</th>
                            <th class="px-4 py-2 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="bill in bills" :key="bill.id">
                            <td class="px-4 py-2">{{ bill.period }}</td>
                            <td class="px-4 py-2 text-right">
                                {{ bill.count }}
                            </td>
                            <td class="px-4 py-2 text-right font-medium">
                                {{ formatCents(bill.amountCents) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ date.format(new Date(bill.dueAt)) }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        bill.overdue
                                            ? 'destructive'
                                            : bill.status === 'open'
                                              ? 'outline'
                                              : 'secondary'
                                    "
                                    >{{ bill.statusLabel }}</Badge
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="recent.length > 0" class="flex flex-col gap-4">
            <Heading variant="small" title="Últimas corridas" />
            <ul class="flex flex-col divide-y rounded-xl border">
                <li
                    v-for="run in recent"
                    :key="run.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm"
                >
                    <Link
                        :href="orderShow(run.orderId)"
                        class="font-medium underline-offset-2 hover:underline"
                        >Pedido nº {{ run.orderNumber }}</Link
                    >
                    <span class="text-muted-foreground">{{
                        run.courierName ?? '—'
                    }}</span>
                    <span
                        v-if="run.createdAt"
                        class="text-xs text-muted-foreground"
                        >{{ dateTime.format(new Date(run.createdAt)) }}</span
                    >
                    <Badge variant="secondary" class="ml-auto">{{
                        run.statusLabel
                    }}</Badge>
                    <span
                        :class="{
                            'font-medium': run.charged,
                            'text-muted-foreground line-through':
                                run.status === 'cancelled',
                        }"
                        >{{ formatCents(run.feeCents) }}</span
                    >
                </li>
            </ul>
        </section>
    </div>
</template>
