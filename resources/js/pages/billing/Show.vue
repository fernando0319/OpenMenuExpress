<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { BadgeCheck, Check, MessageCircle, Ticket, X } from '@lucide/vue';
import { computed } from 'vue';
import BillingController from '@/actions/App/Http/Controllers/BillingController';
import FreeUsageMeter from '@/components/FreeUsageMeter.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatCents } from '@/lib/money';
import { show } from '@/routes/billing';

type SubscriptionInfo = {
    status: string;
    reason: string | null;
    free: { limitCents: number; salesCents: number; overLimit: boolean } | null;
    usage: {
        summary: string | null;
        salesCents: number;
        estimateCents: number;
        freeUpToCents: number | null;
        openInvoice: { period: string; amountCents: number } | null;
    } | null;
    label: string;
    planName: string | null;
    endsAt: string | null;
    daysLeft: number | null;
    graceDaysLeft: number | null;
    blocked: boolean;
};

const props = defineProps<{
    subscription: SubscriptionInfo;
    modules: { value: string; label: string; included: boolean }[];
    plans: {
        id: number;
        name: string;
        priceCents: number;
        interval: string;
        freeUpToCents: number | null;
        usageSummary: string | null;
        modules: string[];
        isCurrent: boolean;
    }[];
    history: {
        id: number;
        code: string;
        plan: string | null;
        days: number;
        at: string | null;
        until: string;
    }[];
    invoices: {
        id: number;
        period: string;
        salesCents: number;
        amountCents: number;
        status: 'open' | 'paid' | 'waived';
        statusLabel: string;
    }[];
    contactWhatsapp: string | null;
    canRedeem: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Assinatura', href: show() }],
    },
});

const form = useForm({ code: '' });

function redeem(): void {
    form.post(BillingController.redeem.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'long' });
const shortDate = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

const headline = computed(() => {
    const s = props.subscription;
    const until = s.endsAt ? date.format(new Date(s.endsAt)) : null;

    switch (s.status) {
        case 'trial':
            return `Teste grátis até ${until} (${s.daysLeft} dia(s) restante(s)).`;
        case 'active':
            return `Plano ${s.planName ?? ''} ativo até ${until}.`;
        case 'free':
            return `Plano ${s.planName ?? 'gratuito'}: grátis enquanto as vendas do mês ficarem até ${formatCents(s.free?.limitCents ?? 0)}. Renova sozinho todo mês.`;
        case 'usage':
            return 'Plano por uso: você paga conforme as vendas do cardápio online. A fatura fecha no fim de cada mês.';
        case 'grace':
            if (s.reason === 'invoice' && s.usage?.openInvoice) {
                return `A fatura de ${s.usage.openInvoice.period} (${formatCents(s.usage.openInvoice.amountCents)}) está em aberto. A loja continua funcionando por mais ${s.graceDaysLeft} dia(s).`;
            }

            return s.reason === 'over_limit'
                ? `A loja vendeu acima do limite gratuito no mês passado. Contrate um plano em até ${s.graceDaysLeft} dia(s).`
                : `Venceu em ${until}. A loja continua funcionando por mais ${s.graceDaysLeft} dia(s).`;
        case 'expired':
            if (s.reason === 'invoice' && s.usage?.openInvoice) {
                return `A fatura de ${s.usage.openInvoice.period} (${formatCents(s.usage.openInvoice.amountCents)}) não foi paga: o painel e o cardápio estão bloqueados até o pagamento.`;
            }

            return s.reason === 'over_limit'
                ? 'A loja passou do limite gratuito: o painel e o cardápio estão bloqueados até a contratação de um plano.'
                : `Venceu em ${until}. O painel e o cardápio estão bloqueados até a renovação.`;
        case 'complimentary':
            return 'Cortesia: a sua loja não tem vencimento.';
        default:
            return '';
    }
});

const tone = computed(() => {
    switch (props.subscription.status) {
        case 'expired':
            return 'border-destructive/40 bg-destructive/10';
        case 'grace':
            return 'border-amber-400/50 bg-amber-500/10';
        default:
            return 'border-primary/30 bg-primary/5';
    }
});

const contactLink = computed(() =>
    props.contactWhatsapp
        ? `https://wa.me/${props.contactWhatsapp}?text=${encodeURIComponent('Olá! Quero renovar a assinatura do OpenMenuExpress.')}`
        : null,
);
</script>

<template>
    <Head title="Assinatura" />

    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Assinatura"
            description="Seu plano, o vencimento e a ativação por voucher."
        />

        <section
            class="flex flex-wrap items-center gap-4 rounded-2xl border p-5"
            :class="tone"
        >
            <span
                class="grid size-12 place-items-center rounded-2xl bg-background text-primary shadow-xs"
            >
                <BadgeCheck class="size-6" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="flex items-center gap-2 font-semibold">
                    {{ subscription.label }}
                    <Badge v-if="subscription.planName" variant="secondary">{{
                        subscription.planName
                    }}</Badge>
                </p>
                <p class="text-sm text-muted-foreground">{{ headline }}</p>
                <FreeUsageMeter
                    v-if="subscription.free"
                    class="mt-3 max-w-md"
                    :limit-cents="subscription.free.limitCents"
                    :sales-cents="subscription.free.salesCents"
                />
                <div
                    v-if="subscription.usage"
                    class="mt-3 flex max-w-xl flex-col gap-2"
                >
                    <dl class="grid grid-cols-2 gap-3 text-sm">
                        <div class="rounded-xl bg-background p-3">
                            <dt class="text-muted-foreground">
                                Vendas online neste mês
                            </dt>
                            <dd class="text-lg font-semibold">
                                {{ formatCents(subscription.usage.salesCents) }}
                            </dd>
                        </div>
                        <div class="rounded-xl bg-background p-3">
                            <dt class="text-muted-foreground">
                                Fatura estimada
                            </dt>
                            <dd class="text-lg font-semibold">
                                {{
                                    subscription.usage.estimateCents > 0
                                        ? formatCents(
                                              subscription.usage.estimateCents,
                                          )
                                        : 'Grátis'
                                }}
                            </dd>
                        </div>
                    </dl>
                    <FreeUsageMeter
                        v-if="subscription.usage.freeUpToCents"
                        :limit-cents="subscription.usage.freeUpToCents"
                        :sales-cents="subscription.usage.salesCents"
                        :hint="
                            subscription.usage.salesCents >
                            subscription.usage.freeUpToCents
                                ? 'Acima do limite gratuito: a cobrança vale sobre este mês.'
                                : 'Até o limite, este mês não tem cobrança.'
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        {{ subscription.usage.summary }} Conta só o valor dos
                        produtos dos pedidos concluídos do cardápio online (sem
                        a taxa de entrega); PDV e mesas não entram.
                    </p>
                </div>
            </div>
            <Button v-if="contactLink" as-child variant="outline">
                <a :href="contactLink" target="_blank" rel="noopener"
                    ><MessageCircle /> Falar sobre a assinatura</a
                >
            </Button>
        </section>

        <div class="grid gap-4 lg:grid-cols-[1fr_22rem]">
            <section class="rounded-2xl border bg-card p-5 shadow-xs">
                <h2 class="mb-1 font-semibold">Ativar voucher</h2>
                <p class="mb-4 text-sm text-muted-foreground">
                    Recebeu um código depois do pagamento? Digite aqui; o tempo
                    é somado ao que você já tem.
                </p>
                <form
                    v-if="canRedeem"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="redeem"
                >
                    <div class="relative min-w-56 flex-1">
                        <Ticket
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="form.code"
                            class="pl-9 font-mono tracking-wider uppercase placeholder:font-sans placeholder:tracking-normal placeholder:normal-case"
                            placeholder="Ex.: MX-7K2P-9QFA"
                            autocomplete="off"
                            aria-label="Código do voucher"
                        />
                    </div>
                    <Button
                        type="submit"
                        :disabled="form.processing || form.code.trim() === ''"
                        >Ativar</Button
                    >
                    <InputError class="w-full" :message="form.errors.code" />
                </form>
                <p v-else class="rounded-lg bg-muted p-3 text-sm">
                    Peça ao dono ou gerente da loja para ativar o voucher.
                </p>

                <h3 class="mt-6 mb-2 text-sm font-medium">
                    Incluído no seu acesso
                </h3>
                <ul class="grid gap-1.5 sm:grid-cols-2">
                    <li class="flex items-center gap-2 text-sm">
                        <Check class="size-4 text-primary" /> Cardápio online,
                        pedidos e catálogo
                    </li>
                    <li
                        v-for="module in modules"
                        :key="module.value"
                        class="flex items-center gap-2 text-sm"
                        :class="{ 'text-muted-foreground': !module.included }"
                    >
                        <Check
                            v-if="module.included"
                            class="size-4 text-primary"
                        />
                        <X v-else class="size-4" />
                        {{ module.label }}
                    </li>
                </ul>
            </section>

            <section class="rounded-2xl border bg-card p-5 shadow-xs">
                <h2 class="mb-3 font-semibold">Histórico</h2>
                <p
                    v-if="history.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nenhum voucher ativado ainda.
                </p>
                <ul v-else class="flex flex-col divide-y text-sm">
                    <li v-for="item in history" :key="item.id" class="py-2">
                        <p class="flex justify-between gap-2">
                            <span class="font-mono">{{ item.code }}</span>
                            <span class="font-medium"
                                >+{{ item.days }} dias</span
                            >
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ item.plan }} ·
                            {{
                                item.at
                                    ? shortDate.format(new Date(item.at))
                                    : ''
                            }}
                            · até {{ shortDate.format(new Date(item.until)) }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>

        <section
            v-if="invoices.length > 0"
            class="rounded-2xl border bg-card p-5 shadow-xs"
        >
            <h2 class="mb-3 font-semibold">Faturas</h2>
            <ul class="flex flex-col divide-y text-sm">
                <li
                    v-for="invoice in invoices"
                    :key="invoice.id"
                    class="flex flex-wrap items-center justify-between gap-2 py-2"
                >
                    <span>
                        <span class="font-medium first-letter:uppercase">{{
                            invoice.period
                        }}</span>
                        <span class="block text-xs text-muted-foreground"
                            >Vendas online:
                            {{ formatCents(invoice.salesCents) }}</span
                        >
                    </span>
                    <span class="flex items-center gap-2">
                        <span class="font-medium tabular-nums">{{
                            formatCents(invoice.amountCents)
                        }}</span>
                        <Badge
                            :variant="
                                invoice.status === 'open' &&
                                invoice.amountCents > 0
                                    ? 'destructive'
                                    : 'secondary'
                            "
                            >{{ invoice.statusLabel }}</Badge
                        >
                    </span>
                </li>
            </ul>
        </section>

        <section v-if="plans.length > 0" class="flex flex-col gap-3">
            <h2 class="font-semibold">Planos</h2>
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="plan in plans"
                    :key="plan.id"
                    class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
                    :class="{
                        'border-primary ring-1 ring-primary': plan.isCurrent,
                    }"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-semibold">{{ plan.name }}</h3>
                        <Badge v-if="plan.isCurrent">Seu plano</Badge>
                    </div>
                    <p v-if="plan.usageSummary" class="text-sm font-medium">
                        {{ plan.usageSummary }}
                    </p>
                    <p v-else>
                        <span class="text-2xl font-semibold">{{
                            formatCents(plan.priceCents)
                        }}</span>
                        <span class="ml-1 text-sm text-muted-foreground">{{
                            plan.interval
                        }}</span>
                    </p>
                    <p
                        v-if="plan.freeUpToCents !== null && !plan.usageSummary"
                        class="-mt-2 text-sm text-muted-foreground"
                    >
                        Grátis enquanto vender até
                        {{ formatCents(plan.freeUpToCents) }} por mês
                    </p>
                    <ul class="flex flex-col gap-1 text-sm">
                        <li class="flex items-center gap-2">
                            <Check class="size-4 text-primary" /> Cardápio,
                            pedidos e catálogo
                        </li>
                        <li
                            v-for="label in plan.modules"
                            :key="label"
                            class="flex items-center gap-2"
                        >
                            <Check class="size-4 text-primary" /> {{ label }}
                        </li>
                    </ul>
                </div>
            </div>
            <p class="text-sm text-muted-foreground">
                Para contratar ou mudar de plano, fale com a gente; você recebe
                um voucher para ativar aqui.
            </p>
        </section>
    </div>
</template>
