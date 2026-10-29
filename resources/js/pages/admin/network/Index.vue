<script setup lang="ts">
import { Head, Link, useForm, usePoll } from '@inertiajs/vue3';
import { Copy } from '@lucide/vue';
import { ref } from 'vue';
import NetworkAdminController from '@/actions/App/Http/Controllers/Admin/NetworkAdminController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/network';
import { index as billsIndex } from '@/routes/admin/network/bills';
import { index as couriersIndex } from '@/routes/admin/network/couriers';
import { index as payoutsIndex } from '@/routes/admin/network/payouts';

const props = defineProps<{
    settings: {
        enabled: boolean;
        platformFeePercent: number;
        minFeeCents: number;
        searchRadiusKm: number;
        billGraceDays: number;
        pixKey: string | null;
        pixHolder: string | null;
    };
    stats: {
        couriersApproved: number;
        couriersPending: number;
        couriersOnline: number;
        monthRuns: number;
        monthFeesCents: number;
        monthPlatformCents: number;
        billsDueCents: number;
        payoutsPendingCents: number;
    };
    live: {
        id: number;
        storeName: string;
        orderNumber: number;
        courierName: string | null;
        status: string;
        statusLabel: string;
        feeCents: number;
        searchingSince: string;
    }[];
    registerUrl: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Rede de entregadores', href: index() },
        ],
    },
});

usePoll(15_000, { only: ['stats', 'live'] });

const minFee = ref(centsToInput(props.settings.minFeeCents));
const form = useForm({
    enabled: props.settings.enabled,
    platform_fee_percent: String(props.settings.platformFeePercent).replace(
        '.',
        ',',
    ),
    min_fee_cents: props.settings.minFeeCents,
    search_radius_km: String(props.settings.searchRadiusKm).replace('.', ','),
    bill_grace_days: props.settings.billGraceDays,
    pix_key: props.settings.pixKey ?? '',
    pix_holder: props.settings.pixHolder ?? '',
});

function save(): void {
    form.transform((data) => ({
        ...data,
        platform_fee_percent: Number(
            String(data.platform_fee_percent).replace(',', '.'),
        ),
        search_radius_km: Number(
            String(data.search_radius_km).replace(',', '.'),
        ),
        min_fee_cents: parseMoney(minFee.value) ?? 0,
    })).put(NetworkAdminController.updateSettings.url(), {
        preserveScroll: true,
    });
}

const copied = ref(false);

async function copyLink(): Promise<void> {
    await navigator.clipboard.writeText(props.registerUrl);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

const time = new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' });
</script>

<template>
    <Head title="Rede de entregadores" />

    <div class="flex flex-col gap-8 p-4 md:p-6">
        <Heading
            title="Rede de entregadores"
            description="Entregadores cadastrados na plataforma, as corridas que as lojas chamam e o dinheiro que passa por elas."
        />

        <nav class="flex flex-wrap gap-2" aria-label="Seções da rede">
            <Button variant="outline" size="sm" as-child>
                <Link :href="couriersIndex()">Entregadores</Link>
            </Button>
            <Button variant="outline" size="sm" as-child>
                <Link :href="billsIndex()">Faturas das lojas</Link>
            </Button>
            <Button variant="outline" size="sm" as-child>
                <Link :href="payoutsIndex()">Repasses</Link>
            </Button>
        </nav>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Link
                :href="couriersIndex()"
                class="rounded-2xl border bg-card p-4 shadow-xs"
            >
                <p class="text-xs text-muted-foreground">
                    Entregadores aprovados
                </p>
                <p class="text-2xl font-semibold">
                    {{ stats.couriersApproved }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ stats.couriersOnline }} online agora
                    <template v-if="stats.couriersPending > 0">
                        ·
                        <strong class="text-amber-600"
                            >{{ stats.couriersPending }} para analisar</strong
                        ></template
                    >
                </p>
            </Link>
            <div class="rounded-2xl border bg-card p-4 shadow-xs">
                <p class="text-xs text-muted-foreground">Corridas no mês</p>
                <p class="text-2xl font-semibold">{{ stats.monthRuns }}</p>
                <p class="text-xs text-muted-foreground">
                    {{ formatCents(stats.monthFeesCents) }} em corridas
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-xs">
                <p class="text-xs text-muted-foreground">
                    Receita da plataforma no mês
                </p>
                <p class="text-2xl font-semibold">
                    {{ formatCents(stats.monthPlatformCents) }}
                </p>
            </div>
            <div
                class="flex flex-col gap-1 rounded-2xl border bg-card p-4 text-sm shadow-xs"
            >
                <Link :href="billsIndex()" class="flex justify-between gap-2">
                    <span class="text-muted-foreground"
                        >A receber das lojas</span
                    >
                    <strong>{{ formatCents(stats.billsDueCents) }}</strong>
                </Link>
                <Link :href="payoutsIndex()" class="flex justify-between gap-2">
                    <span class="text-muted-foreground"
                        >A pagar aos entregadores</span
                    >
                    <strong>{{
                        formatCents(stats.payoutsPendingCents)
                    }}</strong>
                </Link>
            </div>
        </div>

        <section class="flex flex-col gap-3">
            <Heading variant="small" title="Corridas em andamento" />
            <p
                v-if="live.length === 0"
                class="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nenhuma corrida em andamento.
            </p>
            <ul
                v-else
                class="flex flex-col divide-y rounded-2xl border bg-card"
            >
                <li
                    v-for="run in live"
                    :key="run.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm"
                >
                    <span class="font-medium">{{ run.storeName }}</span>
                    <span class="text-muted-foreground"
                        >nº {{ run.orderNumber }}</span
                    >
                    <span class="text-muted-foreground">{{
                        run.courierName ?? '—'
                    }}</span>
                    <span class="text-xs text-muted-foreground"
                        >desde
                        {{ time.format(new Date(run.searchingSince)) }}</span
                    >
                    <Badge
                        :variant="
                            run.status === 'searching' ? 'outline' : 'secondary'
                        "
                        class="ml-auto"
                        >{{ run.statusLabel }}</Badge
                    >
                    <span class="font-medium">{{
                        formatCents(run.feeCents)
                    }}</span>
                </li>
            </ul>
        </section>

        <section class="flex max-w-2xl flex-col gap-4">
            <Heading
                variant="small"
                title="Configurações"
                description="Valem para as próximas corridas; as já chamadas mantêm os valores da hora em que foram criadas."
            />

            <div class="flex flex-col gap-2 rounded-xl border p-4 text-sm">
                <p class="font-medium">Link de cadastro dos entregadores</p>
                <button
                    type="button"
                    class="flex items-center gap-2 text-left break-all text-primary"
                    @click="copyLink"
                >
                    {{ registerUrl }} <Copy class="size-4 shrink-0" />
                </button>
                <p v-if="copied" class="text-xs text-emerald-600">
                    Link copiado.
                </p>
            </div>

            <form class="flex flex-col gap-5" @submit.prevent="save">
                <div class="flex items-center gap-2">
                    <Checkbox id="enabled" v-model="form.enabled" />
                    <Label for="enabled"
                        >Rede ativa (lojas podem chamar entregadores)</Label
                    >
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid content-start gap-2">
                        <Label for="platform_fee_percent"
                            >Taxa da plataforma (%)</Label
                        >
                        <Input
                            id="platform_fee_percent"
                            v-model="form.platform_fee_percent"
                            inputmode="decimal"
                        />
                        <p class="text-xs text-muted-foreground">
                            Descontada do valor da corrida; o resto vai para o
                            entregador.
                        </p>
                        <InputError
                            :message="form.errors.platform_fee_percent"
                        />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="min_fee">Valor mínimo da corrida</Label>
                        <Input
                            id="min_fee"
                            v-model="minFee"
                            inputmode="decimal"
                        />
                        <InputError :message="form.errors.min_fee_cents" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="search_radius_km"
                            >Raio máximo de busca (km)</Label
                        >
                        <Input
                            id="search_radius_km"
                            v-model="form.search_radius_km"
                            inputmode="decimal"
                        />
                        <p class="text-xs text-muted-foreground">
                            A corrida vai primeiro para quem está a até 3 km,
                            depois 5 km, e após 1 minuto para todo o raio. Sem
                            ninguém por perto, já vai direto para mais longe.
                        </p>
                        <InputError :message="form.errors.search_radius_km" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="bill_grace_days"
                            >Dias de tolerância da fatura</Label
                        >
                        <Input
                            id="bill_grace_days"
                            v-model="form.bill_grace_days"
                            type="number"
                            min="0"
                            max="30"
                        />
                        <p class="text-xs text-muted-foreground">
                            Depois do vencimento mais estes dias, a loja não
                            chama novas corridas.
                        </p>
                        <InputError :message="form.errors.bill_grace_days" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="pix_key"
                            >Chave PIX para as lojas pagarem</Label
                        >
                        <Input id="pix_key" v-model="form.pix_key" />
                        <InputError :message="form.errors.pix_key" />
                    </div>
                    <div class="grid content-start gap-2">
                        <Label for="pix_holder">Titular da chave</Label>
                        <Input id="pix_holder" v-model="form.pix_holder" />
                        <InputError :message="form.errors.pix_holder" />
                    </div>
                </div>

                <div>
                    <Button type="submit" :disabled="form.processing"
                        >Salvar</Button
                    >
                </div>
            </form>
        </section>
    </div>
</template>
