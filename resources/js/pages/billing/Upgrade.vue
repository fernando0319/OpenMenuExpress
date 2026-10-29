<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { show as billingShow } from '@/routes/billing';

defineProps<{
    module: { value: string; label: string; description: string };
}>();

const page = usePage();
const canManage = computed(
    () => page.props.currentStore?.canManageStore ?? false,
);
</script>

<template>
    <Head :title="module.label" />

    <div class="flex flex-1 items-center justify-center p-6">
        <div
            class="flex max-w-md flex-col items-center gap-4 rounded-2xl border bg-card p-8 text-center shadow-xs"
        >
            <span
                class="grid size-14 place-items-center rounded-2xl bg-primary/10 text-primary"
            >
                <Lock class="size-6" />
            </span>
            <h1 class="text-xl font-semibold">
                {{ module.label }} não está no seu plano
            </h1>
            <p class="text-sm text-muted-foreground">
                {{ module.description }}
            </p>
            <div class="flex flex-wrap justify-center gap-2">
                <Button v-if="canManage" as-child>
                    <Link :href="billingShow()">Ver planos e assinatura</Link>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="dashboard()">Voltar ao início</Link>
                </Button>
            </div>
            <p v-if="!canManage" class="text-xs text-muted-foreground">
                Fale com o dono ou gerente da loja para mudar de plano.
            </p>
        </div>
    </div>
</template>
