<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight } from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * One headline number: label, value and an optional change against a named period.
 */
const props = defineProps<{
    label: string;
    value: string;
    icon: Component;
    /** Current and previous raw values, for the change; omitted when there is nothing to compare. */
    current?: number;
    previous?: number;
    comparedTo?: string;
    hint?: string;
}>();

const delta = computed(() => {
    if (
        props.current === undefined ||
        props.previous === undefined ||
        props.previous === 0
    ) {
        return null;
    }

    const change = Math.round(
        ((props.current - props.previous) / props.previous) * 100,
    );

    return { change, up: change >= 0 };
});
</script>

<template>
    <div class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs">
        <div class="flex items-center justify-between gap-2">
            <span class="text-sm text-muted-foreground">{{ label }}</span>
            <span
                class="grid size-9 place-items-center rounded-xl bg-primary/10 text-primary"
            >
                <component :is="icon" class="size-4" />
            </span>
        </div>
        <span class="text-2xl font-semibold tracking-tight">{{ value }}</span>
        <span class="flex items-center gap-1 text-xs text-muted-foreground">
            <template v-if="delta">
                <span
                    class="inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 font-medium"
                    :class="
                        delta.up
                            ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                            : 'bg-rose-500/10 text-rose-700 dark:text-rose-400'
                    "
                >
                    <ArrowUpRight
                        v-if="delta.up"
                        class="size-3"
                        aria-hidden="true"
                    />
                    <ArrowDownRight v-else class="size-3" aria-hidden="true" />
                    {{ delta.up ? '+' : '' }}{{ delta.change }}%
                </span>
                {{ comparedTo }}
            </template>
            <template v-else-if="hint">{{ hint }}</template>
            <template v-else>&nbsp;</template>
        </span>
    </div>
</template>
