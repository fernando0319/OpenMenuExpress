<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ExternalLink, LifeBuoy } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminController from '@/actions/App/Http/Controllers/Admin/AdminController';
import StoreSubscriptionCard from '@/components/admin/StoreSubscriptionCard.vue';
import BarChart from '@/components/charts/BarChart.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/stores';
import { show as menuShow } from '@/routes/menu';

const props = defineProps<{
    store: {
        id: number;
        name: string;
        slug: string;
        whatsappNumber: string | null;
        createdAt: string | null;
        timezone: string;
        isPaused: boolean;
        isSuspended: boolean;
        suspendedAt: string | null;
        suspensionReason: string | null;
    };
    members: { id: number; name: string; login: string; role: string }[];
    paymentMethods: { label: string; active: boolean }[];
    subscription: {
        status: string;
        label: string;
        planName: string | null;
        endsAt: string | null;
        daysLeft: number | null;
        planId: number | null;
        isComplimentary: boolean;
        trialEndsAt: string | null;
        paidUntil: string | null;
        free: { limitCents: number; salesCents: number } | null;
    };
    plans: { id: number; name: string; is_active: boolean }[];
    billingEnabled: boolean;
    totals: {
        revenueCents: number;
        salesCount: number;
        averageTicketCents: number;
        cancelledCount: number;
    };
    daily: { date: string; revenueCents: number; salesCount: number }[];
    health: {
        whatsappEnabled: boolean;
        whatsappConnected: boolean;
        messageFailures7d: number;
        printFailures7d: number;
        agents: { name: string; online: boolean; lastSeenAt: string | null }[];
    };
    recentOrders: {
        id: number;
        number: number;
        channel: string;
        status: string;
        totalCents: number;
        placedAt: string;
    }[];
    audit: {
        id: number;
        action: string;
        by: string | null;
        details: Record<string, unknown> | null;
        at: string | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Lojas', href: index() },
        ],
    },
});

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
const day = new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'UTC',
});

function formatDate(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '—';
}

const bars = computed(() =>
    props.daily.map((row) => {
        const date = new Date(`${row.date}T00:00:00Z`);

        return {
            label: day.format(date),
            value: row.revenueCents,
            title: `${day.format(date)}: ${formatCents(row.revenueCents)} em ${row.salesCount} venda(s)`,
        };
    }),
);

const supportOpen = ref(false);
const supportForm = useForm({ mode: 'read' as 'read' | 'full', reason: '' });

function startSupport(): void {
    supportForm.post(AdminController.startSupport.url(props.store.id));
}

const suspendOpen = ref(false);
const suspendForm = useForm({ reason: '' });

function suspend(): void {
    suspendForm.post(AdminController.suspend.url(props.store.id), {
        preserveScroll: true,
        onSuccess: () => {
            suspendOpen.value = false;
        },
    });
}

function reactivate(): void {
    if (
        confirm(
            `Reativar ${props.store.name}? O cardápio e o painel voltam a funcionar na hora.`,
        )
    ) {
        router.post(
            AdminController.reactivate.url(props.store.id),
            {},
            { preserveScroll: true },
        );
    }
}

function detailText(details: Record<string, unknown> | null): string {
    const reason = details?.reason ?? details?.previous_reason;

    return typeof reason === 'string' ? reason : '';
}
</script>

<template>
    <Head :title="store.name" />

    <div class="flex max-w-6xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                :title="store.name"
                :description="`/${store.slug} · criada em ${formatDate(store.createdAt)}`"
            />
            <div class="ml-auto flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <a
                        :href="menuShow.url(store.slug)"
                        target="_blank"
                        rel="noopener"
                    >
                        <ExternalLink />
                        Ver cardápio
                    </a>
                </Button>
                <Button
                    variant="outline"
                    @click="
                        supportForm.reset();
                        supportForm.clearErrors();
                        supportOpen = true;
                    "
                >
                    <LifeBuoy />
                    Entrar como suporte
                </Button>
                <Button v-if="store.isSuspended" @click="reactivate"
                    >Reativar loja</Button
                >
                <Button
                    v-else
                    variant="destructive"
                    @click="
                        suspendForm.reset();
                        suspendForm.clearErrors();
                        suspendOpen = true;
                    "
                >
                    Suspender loja
                </Button>
            </div>
        </div>

        <div
            v-if="store.isSuspended"
            class="rounded-xl border border-destructive/40 bg-destructive/10 p-4 text-sm"
        >
            <strong>Suspensa em {{ formatDate(store.suspendedAt) }}.</strong>
            O cardápio está fora do ar e a equipe não acessa o painel.
            <p v-if="store.suspensionReason" class="mt-1">
                Motivo: {{ store.suspensionReason }}
            </p>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Vendas (30 dias)</p>
                <p class="text-2xl font-semibold">
                    {{ formatCents(totals.revenueCents) }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Nº de vendas</p>
                <p class="text-2xl font-semibold">{{ totals.salesCount }}</p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Ticket médio</p>
                <p class="text-2xl font-semibold">
                    {{ formatCents(totals.averageTicketCents) }}
                </p>
            </div>
            <div class="rounded-xl border p-4">
                <p class="text-sm text-muted-foreground">Cancelados</p>
                <p class="text-2xl font-semibold">
                    {{ totals.cancelledCount }}
                </p>
            </div>
        </div>

        <StoreSubscriptionCard
            :store-id="store.id"
            :subscription="subscription"
            :plans="plans"
            :billing-enabled="billingEnabled"
        />

        <section class="rounded-xl border p-4">
            <h2 class="mb-3 font-medium">Faturamento por dia (30 dias)</h2>
            <BarChart :items="bars" :label-every="5" />
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <section class="rounded-xl border p-4 text-sm">
                <h2 class="mb-3 font-medium">Saúde</h2>
                <dl class="grid gap-2">
                    <div class="flex justify-between gap-2">
                        <dt>WhatsApp</dt>
                        <dd>
                            <Badge
                                :variant="
                                    health.whatsappConnected
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{
                                    health.whatsappConnected
                                        ? 'Conectado'
                                        : 'Não conectado'
                                }}
                            </Badge>
                            <span
                                v-if="!health.whatsappEnabled"
                                class="ml-1 text-muted-foreground"
                                >(desligado)</span
                            >
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Mensagens com falha (7d)</dt>
                        <dd
                            :class="{
                                'font-medium text-destructive':
                                    health.messageFailures7d > 0,
                            }"
                        >
                            {{ health.messageFailures7d }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Impressões com falha (7d)</dt>
                        <dd
                            :class="{
                                'font-medium text-destructive':
                                    health.printFailures7d > 0,
                            }"
                        >
                            {{ health.printFailures7d }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>Loja pausada</dt>
                        <dd>{{ store.isPaused ? 'Sim' : 'Não' }}</dd>
                    </div>
                </dl>
                <h3 class="mt-4 mb-2 font-medium">Formas de pagamento</h3>
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="method in paymentMethods.filter(
                            (row) => row.active,
                        )"
                        :key="method.label"
                        variant="secondary"
                        >{{ method.label }}</Badge
                    >
                </div>
                <h3 class="mt-4 mb-2 font-medium">Impressão</h3>
                <p
                    v-if="health.agents.length === 0"
                    class="text-muted-foreground"
                >
                    Nenhum computador pareado.
                </p>
                <ul v-else class="flex flex-col gap-1">
                    <li
                        v-for="agent in health.agents"
                        :key="agent.name"
                        class="flex justify-between gap-2"
                    >
                        <span>{{ agent.name }}</span>
                        <span
                            :class="
                                agent.online
                                    ? 'text-emerald-600'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                agent.online
                                    ? 'Online'
                                    : `Visto ${formatDate(agent.lastSeenAt)}`
                            }}
                        </span>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border text-sm">
                <h2 class="border-b p-4 font-medium">Equipe</h2>
                <ul class="divide-y">
                    <li
                        v-for="member in members"
                        :key="member.id"
                        class="flex justify-between gap-2 px-4 py-2"
                    >
                        <span class="min-w-0">
                            <span class="block truncate">{{
                                member.name
                            }}</span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ member.login }}</span
                            >
                        </span>
                        <Badge variant="outline">{{ member.role }}</Badge>
                    </li>
                </ul>
            </section>

            <section class="rounded-xl border text-sm">
                <h2 class="border-b p-4 font-medium">Últimos pedidos</h2>
                <p
                    v-if="recentOrders.length === 0"
                    class="p-4 text-muted-foreground"
                >
                    Nenhum pedido ainda.
                </p>
                <ul v-else class="divide-y">
                    <li
                        v-for="order in recentOrders"
                        :key="order.id"
                        class="flex justify-between gap-2 px-4 py-2"
                    >
                        <span>
                            nº {{ order.number }}
                            <span class="block text-xs text-muted-foreground"
                                >{{ order.channel }} ·
                                {{ formatDate(order.placedAt) }}</span
                            >
                        </span>
                        <span class="text-right">
                            {{ formatCents(order.totalCents) }}
                            <span class="block text-xs text-muted-foreground">{{
                                order.status
                            }}</span>
                        </span>
                    </li>
                </ul>
            </section>
        </div>

        <section class="rounded-xl border text-sm">
            <h2 class="border-b p-4 font-medium">Histórico de intervenções</h2>
            <p v-if="audit.length === 0" class="p-4 text-muted-foreground">
                Nenhuma intervenção nesta loja.
            </p>
            <ul v-else class="divide-y">
                <li
                    v-for="row in audit"
                    :key="row.id"
                    class="flex flex-col px-4 py-2"
                >
                    <span
                        ><strong>{{ row.action }}</strong> ·
                        {{ row.by ?? '—' }} · {{ formatDate(row.at) }}</span
                    >
                    <span
                        v-if="detailText(row.details)"
                        class="text-muted-foreground"
                        >{{ detailText(row.details) }}</span
                    >
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="supportOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Entrar no painel de {{ store.name }}</DialogTitle>
                <DialogDescription>
                    Você continua com a sua conta e vê a loja como o dono. O
                    acesso dura 60 minutos, fica no histórico de intervenções e
                    aparece para o lojista na tela Equipe.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="startSupport">
                <div class="grid grid-cols-2 gap-2">
                    <Button
                        type="button"
                        :variant="
                            supportForm.mode === 'read' ? 'default' : 'outline'
                        "
                        @click="supportForm.mode = 'read'"
                    >
                        Somente leitura
                    </Button>
                    <Button
                        type="button"
                        :variant="
                            supportForm.mode === 'full' ? 'default' : 'outline'
                        "
                        @click="supportForm.mode = 'full'"
                    >
                        Pode alterar
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{
                        supportForm.mode === 'read'
                            ? 'Só olhar: qualquer alteração é bloqueada.'
                            : 'Cada alteração fica registrada com o seu nome.'
                    }}
                </p>
                <TextareaField
                    v-model="supportForm.reason"
                    maxlength="300"
                    class="min-h-20"
                    placeholder="Motivo (ex.: lojista pediu ajuda com a impressão)"
                    aria-label="Motivo do acesso"
                />
                <InputError :message="supportForm.errors.reason" />
                <DialogFooter>
                    <Button type="submit" :disabled="supportForm.processing"
                        >Entrar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="suspendOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Suspender {{ store.name }}</DialogTitle>
                <DialogDescription>
                    O cardápio sai do ar e a equipe deixa de acessar o painel
                    até a reativação. Os dados não são apagados.
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="suspend">
                <TextareaField
                    v-model="suspendForm.reason"
                    maxlength="500"
                    class="min-h-20"
                    placeholder="Motivo (aparece para o lojista)"
                    aria-label="Motivo da suspensão"
                />
                <InputError :message="suspendForm.errors.reason" />
                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="suspendForm.processing"
                        >Suspender</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
