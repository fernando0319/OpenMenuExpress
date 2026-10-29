<script setup lang="ts">
import { computed } from 'vue';

/**
 * Column chart for one series, without a chart library.
 * Thin columns (max 24px) with a rounded top, one hairline baseline, a tooltip on hover or
 * keyboard focus, and only the highest column labeled. Color comes from --primary.
 */
const props = withDefaults(
    defineProps<{
        items: { label: string; value: number; title: string }[];
        /** Show only every n-th label so long ranges stay readable. */
        labelEvery?: number;
        height?: string;
        /** Formats the label on the highest column; without it, no value is shown. */
        format?: (value: number) => string;
    }>(),
    { labelEvery: 1, height: '10rem', format: undefined },
);

const max = computed(() =>
    Math.max(0, ...props.items.map((item) => item.value)),
);
const peakIndex = computed(() =>
    max.value > 0
        ? props.items.findIndex((item) => item.value === max.value)
        : -1,
);

function heightOf(value: number): string {
    if (max.value === 0 || value <= 0) {
        return '0px';
    }

    // Leaves room above the highest column for its label.
    return `max(4px, ${(value / max.value) * (props.format ? 82 : 100)}%)`;
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div
            class="relative flex items-end border-b border-border"
            :style="{ height }"
            role="img"
            :aria-label="items.map((item) => item.title).join('; ')"
        >
            <div
                v-for="(item, position) in items"
                :key="position"
                class="group relative flex h-full flex-1 items-end justify-center px-px outline-none"
                tabindex="0"
                :aria-label="item.title"
            >
                <span
                    v-if="format && position === peakIndex"
                    class="absolute left-1/2 -translate-x-1/2 text-[11px] font-medium whitespace-nowrap text-muted-foreground"
                    :style="{ bottom: `calc(${heightOf(item.value)} + 4px)` }"
                    >{{ format(item.value) }}</span
                >
                <div
                    class="w-full max-w-6 rounded-t-[4px] bg-primary transition-opacity group-hover:opacity-80 group-focus-visible:opacity-80"
                    :style="{ height: heightOf(item.value) }"
                />
                <div
                    class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-1 hidden -translate-x-1/2 rounded-lg border bg-popover px-2.5 py-1.5 text-xs whitespace-nowrap text-popover-foreground shadow-md group-hover:block group-focus-visible:block"
                >
                    {{ item.title }}
                </div>
            </div>
        </div>
        <div class="flex text-[10px] text-muted-foreground">
            <span
                v-for="(item, position) in items"
                :key="position"
                class="flex-1 truncate text-center"
            >
                {{ position % labelEvery === 0 ? item.label : '' }}
            </span>
        </div>
    </div>
</template>
