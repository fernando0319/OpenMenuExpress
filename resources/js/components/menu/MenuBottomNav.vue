<script setup lang="ts">
import { Heart, House, ReceiptText, ShoppingBasket, Store } from '@lucide/vue';

export type MenuTab = 'home' | 'favorites';

/**
 * App-like bottom bar of the customer menu, with the cart in the middle.
 */
defineProps<{
    tab: MenuTab;
    cartCount: number;
}>();

const emit = defineEmits<{
    home: [];
    info: [];
    cart: [];
    favorites: [];
    orders: [];
}>();
</script>

<template>
    <nav
        class="fixed inset-x-3 bottom-3 z-40 mx-auto mb-[env(safe-area-inset-bottom)] flex h-16 max-w-md items-center justify-around rounded-3xl bg-white px-2 shadow-[0_12px_40px_-12px_rgb(30_39_86/0.35)]"
        aria-label="Navegação do cardápio"
    >
        <button
            type="button"
            class="grid size-11 place-items-center rounded-2xl"
            :class="tab === 'home' ? 'text-brand-accent' : 'text-slate-400'"
            aria-label="Início"
            :aria-current="tab === 'home' ? 'page' : undefined"
            @click="emit('home')"
        >
            <House class="size-6" />
        </button>
        <button
            type="button"
            class="grid size-11 place-items-center rounded-2xl text-slate-400"
            aria-label="Informações da loja"
            @click="emit('info')"
        >
            <Store class="size-6" />
        </button>

        <button
            type="button"
            class="relative -mt-9 grid size-16 place-items-center rounded-full bg-brand text-white shadow-lg ring-4 shadow-brand/40 ring-brand-surface"
            :aria-label="
                cartCount > 0 ? `Carrinho, ${cartCount} item(ns)` : 'Carrinho'
            "
            @click="emit('cart')"
        >
            <ShoppingBasket class="size-7" />
            <span
                v-if="cartCount > 0"
                class="absolute -top-1 -right-1 grid min-w-6 place-items-center rounded-full bg-brand-accent px-1.5 text-xs font-bold text-white ring-2 ring-white"
                >{{ cartCount }}</span
            >
        </button>

        <button
            type="button"
            class="grid size-11 place-items-center rounded-2xl"
            :class="tab === 'favorites' ? 'text-rose-500' : 'text-slate-400'"
            aria-label="Favoritos"
            :aria-current="tab === 'favorites' ? 'page' : undefined"
            @click="emit('favorites')"
        >
            <Heart
                class="size-6"
                :class="{ 'fill-rose-500': tab === 'favorites' }"
            />
        </button>
        <button
            type="button"
            class="grid size-11 place-items-center rounded-2xl text-slate-400"
            aria-label="Meus pedidos"
            @click="emit('orders')"
        >
            <ReceiptText class="size-6" />
        </button>
    </nav>
</template>
