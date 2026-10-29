<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { LifeBuoy } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import AdminController from '@/actions/App/Http/Controllers/Admin/AdminController';
import { Button } from '@/components/ui/button';

/**
 * Always visible while the platform admin is inside a store's panel (support mode).
 */
const page = usePage();
const support = computed(() => page.props.support);

const now = ref(Date.now());
const ticker = setInterval(() => (now.value = Date.now()), 30_000);
onBeforeUnmount(() => clearInterval(ticker));

const minutesLeft = computed(() =>
    support.value
        ? Math.max(
              0,
              Math.ceil(
                  (new Date(support.value.expiresAt).getTime() - now.value) /
                      60_000,
              ),
          )
        : 0,
);

function leave(): void {
    router.post(AdminController.stopSupport.url());
}
</script>

<template>
    <div
        v-if="support"
        class="flex flex-wrap items-center gap-3 border-b border-amber-300 bg-amber-100 px-4 py-2 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        role="status"
    >
        <LifeBuoy class="size-4 shrink-0" />
        <span class="flex-1">
            <strong>Modo suporte</strong> em {{ support.storeName }} ·
            {{
                support.mode === 'full'
                    ? 'pode alterar (cada alteração é registrada)'
                    : 'somente leitura'
            }}
            · termina em {{ minutesLeft }} min
        </span>
        <Button
            size="sm"
            variant="outline"
            class="bg-transparent"
            @click="leave"
            >Sair do modo suporte</Button
        >
    </div>
</template>
