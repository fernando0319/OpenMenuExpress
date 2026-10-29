<script setup lang="ts">
import { computed } from 'vue';

/**
 * Rows with a horizontal bar showing each one's share of the total.
 */
const props = defineProps<{
    rows: {
        key: string;
        label: string;
        value: number;
        display: string;
        hint?: string;
    }[];
}>();

const total = computed(() =>
    props.rows.reduce((sum, row) => sum + Math.max(0, row.value), 0),
);
</script>

<template>
    <ul class="flex flex-col gap-3 text-sm">
        <li v-for="row in rows" :key="row.key" class="flex flex-col gap-1">
            <div class="flex justify-between gap-2">
                <span>{{ row.label }}</span>
                <span class="font-medium whitespace-nowrap">
                    {{ row.display }}
                    <span
                        v-if="row.hint"
                        class="font-normal text-muted-foreground"
                        >· {{ row.hint }}</span
                    >
                </span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full rounded-full bg-primary"
                    :style="{
                        width: `${total > 0 ? (Math.max(0, row.value) / total) * 100 : 0}%`,
                    }"
                />
            </div>
        </li>
    </ul>
</template>
