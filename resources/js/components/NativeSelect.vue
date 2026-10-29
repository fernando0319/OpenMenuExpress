<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const model = defineModel<string | number | null>();

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <select
        v-model="model"
        data-slot="native-select"
        :class="
            cn(
                'h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50 md:text-sm dark:bg-input/30',
                props.class,
            )
        "
    >
        <slot />
    </select>
</template>
