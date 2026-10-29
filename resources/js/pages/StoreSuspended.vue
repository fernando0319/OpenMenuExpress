<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { logout } from '@/routes';

defineOptions({
    layout: {
        title: 'Loja suspensa',
        description: 'O acesso ao painel desta loja está bloqueado no momento.',
    },
});

defineProps<{
    storeName: string;
    reason: string | null;
}>();
</script>

<template>
    <Head title="Loja suspensa" />

    <div class="flex flex-col gap-4 text-center text-sm">
        <p>
            A loja <strong>{{ storeName }}</strong> foi suspensa pela
            administração da plataforma. O cardápio também está fora do ar.
        </p>
        <p v-if="reason" class="rounded-lg border p-3 text-left">
            <strong>Motivo:</strong> {{ reason }}
        </p>
        <p class="text-muted-foreground">
            Entre em contato com o suporte para resolver e reativar a loja.
        </p>
        <Link
            :href="logout()"
            as="button"
            class="text-primary underline-offset-4 hover:underline"
            >Sair</Link
        >
    </div>
</template>
