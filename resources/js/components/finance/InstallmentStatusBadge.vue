<script setup lang="ts">
import { AlertCircle, CheckCircle2, Circle, XCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { InstallmentStatus } from '@/types/finance';

/**
 * Status of an installment: icon + word, so it never depends on color alone.
 */
const props = defineProps<{
    status: InstallmentStatus;
    label: string;
}>();

const look = computed(() => {
    switch (props.status) {
        case 'overdue':
            return {
                icon: AlertCircle,
                class: 'bg-destructive/10 text-destructive dark:bg-destructive/20',
            };
        case 'paid':
            return {
                icon: CheckCircle2,
                class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
            };
        case 'canceled':
            return {
                icon: XCircle,
                class: 'bg-muted text-muted-foreground',
            };
        default:
            return {
                icon: Circle,
                class: 'border bg-background text-foreground',
            };
    }
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap"
        :class="look.class"
    >
        <component :is="look.icon" class="size-3.5" aria-hidden="true" />
        {{ label }}
    </span>
</template>
