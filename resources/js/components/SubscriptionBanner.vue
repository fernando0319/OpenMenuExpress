<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarClock } from '@lucide/vue';
import { computed } from 'vue';
import { formatCents } from '@/lib/money';
import { show as billingShow } from '@/routes/billing';

/**
 * Close to the end of the trial or plan, and during the grace period: a warning on every screen.
 */
const page = usePage();
const subscription = computed(() => page.props.currentStore?.subscription);
const canManage = computed(
    () => page.props.currentStore?.canManageStore ?? false,
);

const message = computed(() => {
    const s = subscription.value;

    // The platform admin's own pages are not about this store.
    if (!s?.warn || page.component.startsWith('admin/')) {
        return null;
    }

    if (s.status === 'free' && s.free?.overLimit) {
        return `Você vendeu ${formatCents(s.free.salesCents)} este mês e passou do limite gratuito de ${formatCents(s.free.limitCents)}. A partir do próximo mês será preciso um plano pago.`;
    }

    if (
        s.status === 'grace' &&
        s.reason === 'invoice' &&
        s.usage?.openInvoice
    ) {
        return `A fatura de ${s.usage.openInvoice.period} (${formatCents(s.usage.openInvoice.amountCents)}) está em aberto. Pague em até ${s.graceDaysLeft} dia(s) para não ter o painel e o cardápio bloqueados.`;
    }

    if (s.status === 'grace' && s.reason === 'over_limit') {
        return `Sua loja passou do limite gratuito no mês passado. Contrate um plano em até ${s.graceDaysLeft} dia(s) para não ter o painel e o cardápio bloqueados.`;
    }

    if (s.status === 'grace') {
        return `A assinatura venceu. A loja continua funcionando por mais ${s.graceDaysLeft} dia(s); depois o painel e o cardápio são bloqueados.`;
    }

    const days = s.daysLeft ?? 0;
    const when = days <= 0 ? 'hoje' : days === 1 ? 'amanhã' : `em ${days} dias`;

    return s.status === 'trial'
        ? `Seu teste grátis termina ${when}.`
        : `Sua assinatura vence ${when}.`;
});
</script>

<template>
    <div
        v-if="message"
        class="flex flex-wrap items-center gap-3 border-b px-4 py-2 text-sm"
        :class="
            subscription?.status === 'grace'
                ? 'border-destructive/30 bg-destructive/10'
                : 'border-amber-400/40 bg-amber-500/10'
        "
        role="status"
    >
        <CalendarClock class="size-4 shrink-0" />
        <span class="flex-1">{{ message }}</span>
        <Link
            v-if="canManage"
            :href="billingShow()"
            class="font-medium underline-offset-4 hover:underline"
            >{{
                subscription?.reason === 'invoice' ? 'Ver fatura' : 'Renovar'
            }}</Link
        >
    </div>
</template>
