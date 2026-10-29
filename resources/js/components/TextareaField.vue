<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const model = defineModel<string | null>();

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <textarea
        v-model="model"
        data-slot="textarea"
        :class="
            cn(
                'min-h-20 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30',
                props.class,
            )
        "
    />
</template>
