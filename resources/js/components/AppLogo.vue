<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const name = usePage().props.name;
</script>

<template>
    <img
        src="/images/logo-me-wide.png"
        alt=""
        class="h-6 w-auto shrink-0 object-contain"
    />
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">{{
            name
        }}</span>
    </div>
</template>
