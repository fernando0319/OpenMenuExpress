<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    storeName: string;
}>();
</script>

<template>
    <Head :title="storeName" />

    <main
        class="flex min-h-svh flex-col items-center justify-center gap-2 p-6 text-center"
    >
        <h1 class="text-xl font-semibold">{{ storeName }}</h1>
        <p class="text-muted-foreground">
            O cardápio está indisponível no momento. Tente novamente mais tarde.
        </p>
    </main>
</template>
