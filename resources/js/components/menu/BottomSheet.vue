<script setup lang="ts">
import { X } from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';

/**
 * Panel that slides up from the bottom on phones (a centered card on larger screens).
 */
defineProps<{
    title: string;
    description?: string;
}>();

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-slate-900/50 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="fixed inset-x-0 bottom-0 z-50 flex max-h-[85dvh] flex-col rounded-t-[2rem] bg-white font-display shadow-2xl data-[state=closed]:animate-out data-[state=closed]:slide-out-to-bottom data-[state=open]:animate-in data-[state=open]:slide-in-from-bottom sm:inset-auto sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-md sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-[2rem]"
            >
                <div
                    class="mx-auto mt-3 h-1.5 w-12 rounded-full bg-slate-200 sm:hidden"
                />
                <div class="flex items-start gap-3 px-6 pt-4 pb-2">
                    <div class="flex-1">
                        <DialogTitle class="text-lg font-semibold text-brand">{{
                            title
                        }}</DialogTitle>
                        <DialogDescription
                            v-if="description"
                            class="text-sm text-muted-foreground"
                            >{{ description }}</DialogDescription
                        >
                        <DialogDescription v-else class="sr-only">{{
                            title
                        }}</DialogDescription>
                    </div>
                    <DialogClose
                        class="grid size-9 place-items-center rounded-full bg-brand-surface text-brand-ink"
                        aria-label="Fechar"
                    >
                        <X class="size-4" />
                    </DialogClose>
                </div>
                <div
                    class="overflow-y-auto px-6 pt-2 pb-[max(1.5rem,env(safe-area-inset-bottom))]"
                >
                    <slot />
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
