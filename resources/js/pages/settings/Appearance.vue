<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Tema do painel',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Tema do painel" />

    <h1 class="sr-only">Tema do painel</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Tema do painel"
            description="Claro, escuro ou igual ao do seu aparelho"
        />
        <AppearanceTabs />
    </div>
</template>
