<script setup lang="ts">
import { computed } from 'vue';
import { formatCents } from '@/lib/money';

/**
 * This month's sales against the free plan's limit.
 */
const props = defineProps<{
    limitCents: number;
    salesCents: number;
    /** Replaces the text under the bar (plans charged by use explain it differently). */
    hint?: string;
}>();

const share = computed(() =>
    props.limitCents > 0 ? props.salesCents / props.limitCents : 0,
);
const percent = computed(() => Math.round(share.value * 100));
const tone = computed(() =>
    share.value > 1
        ? 'bg-destructive'
        : share.value >= 0.8
          ? 'bg-amber-500'
          : 'bg-primary',
);
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex items-baseline justify-between gap-3 text-sm">
            <span>
                <strong>{{ formatCents(salesCents) }}</strong>
                <span class="text-muted-foreground">
                    de {{ formatCents(limitCents) }} neste mês</span
                >
            </span>
            <span class="text-muted-foreground">{{ percent }}%</span>
        </div>
        <div
            class="h-2 overflow-hidden rounded-full bg-muted"
            role="meter"
            :aria-valuenow="salesCents"
            :aria-valuemin="0"
            :aria-valuemax="limitCents"
            :aria-label="`${formatCents(salesCents)} de ${formatCents(limitCents)} do limite gratuito`"
        >
            <div
                class="h-full rounded-full transition-all"
                :class="tone"
                :style="{ width: `${Math.min(100, percent)}%` }"
            />
        </div>
        <p class="text-xs text-muted-foreground">
            <template v-if="hint">{{ hint }}</template>
            <template v-else-if="share > 1"
                >Passou do limite: a partir do próximo mês será preciso um plano
                pago.</template
            >
            <template v-else-if="share >= 0.8"
                >Perto do limite gratuito.</template
            >
            <template v-else
                >Grátis enquanto as vendas do mês ficarem dentro do limite.
                Renova sozinho todo mês.</template
            >
        </p>
    </div>
</template>
