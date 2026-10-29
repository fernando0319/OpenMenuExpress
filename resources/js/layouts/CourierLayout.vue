<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Bike, Download, LogOut, UserRound, Wallet } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Toaster } from '@/components/ui/sonner';
import { logout } from '@/routes';
import { earnings, home, profile } from '@/routes/courier';

/**
 * The courier app shell: made for the phone, installable on the home screen (PWA),
 * with the sections at the bottom, within reach of the thumb.
 */
const page = usePage();
const signedUp = computed(() => page.component !== 'courier/Register');

const tabs = [
    { title: 'Corridas', href: home(), icon: Bike, component: 'courier/Home' },
    {
        title: 'Ganhos',
        href: earnings(),
        icon: Wallet,
        component: 'courier/Earnings',
    },
    {
        title: 'Perfil',
        href: profile(),
        icon: UserRound,
        component: 'courier/Profile',
    },
];

type InstallPrompt = Event & { prompt: () => Promise<void> };

const installPrompt = ref<InstallPrompt | null>(null);
const standalone = ref(false);
const isIos = ref(false);

onMounted(() => {
    standalone.value =
        window.matchMedia('(display-mode: standalone)').matches ||
        (navigator as Navigator & { standalone?: boolean }).standalone === true;
    isIos.value = /iphone|ipad|ipod/i.test(navigator.userAgent);

    if ('serviceWorker' in navigator) {
        void navigator.serviceWorker
            .register('/courier-sw.js', { scope: '/entregador' })
            .catch(() => {});
    }

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installPrompt.value = event as InstallPrompt;
    });
});

async function install(): Promise<void> {
    await installPrompt.value?.prompt();
    installPrompt.value = null;
}

function signOut(): void {
    router.flushAll();
}
</script>

<template>
    <Head>
        <link rel="manifest" href="/entregador/manifest.webmanifest" />
        <meta name="theme-color" content="#7b5cff" />
        <meta name="mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-capable" content="yes" />
        <meta name="apple-mobile-web-app-title" content="Entregador" />
    </Head>

    <div class="flex min-h-svh flex-col bg-muted/40">
        <header
            class="sticky top-0 z-20 flex items-center gap-3 border-b bg-background/95 px-4 py-3 backdrop-blur"
        >
            <AppLogoIcon class="size-8" />
            <div class="flex-1 leading-tight">
                <p class="text-sm font-semibold">OpenMenuExpress</p>
                <p class="text-xs text-muted-foreground">Entregador</p>
            </div>
            <button
                v-if="installPrompt && !standalone"
                type="button"
                class="inline-flex items-center gap-1.5 rounded-full bg-primary px-3 py-1.5 text-xs font-medium text-primary-foreground"
                @click="install"
            >
                <Download class="size-3.5" />
                Instalar app
            </button>
            <Link
                v-if="signedUp"
                :href="logout()"
                as="button"
                class="rounded-full p-2 text-muted-foreground hover:bg-muted"
                aria-label="Sair"
                @click="signOut"
            >
                <LogOut class="size-5" />
            </Link>
        </header>

        <p
            v-if="isIos && !standalone && signedUp"
            class="border-b bg-background px-4 py-2 text-xs text-muted-foreground"
        >
            Para instalar no iPhone: toque em
            <strong>Compartilhar</strong> e depois em
            <strong>Adicionar à Tela de Início</strong>.
        </p>

        <main
            class="mx-auto flex w-full max-w-lg flex-1 flex-col gap-4 p-4"
            :class="signedUp ? 'pb-24' : ''"
        >
            <slot />
        </main>

        <nav
            v-if="signedUp"
            class="fixed inset-x-0 bottom-0 z-20 border-t bg-background pb-[env(safe-area-inset-bottom)]"
        >
            <div class="mx-auto grid max-w-lg grid-cols-3">
                <Link
                    v-for="tab in tabs"
                    :key="tab.title"
                    :href="tab.href"
                    class="flex flex-col items-center gap-0.5 py-2.5 text-xs font-medium"
                    :class="
                        page.component === tab.component
                            ? 'text-primary'
                            : 'text-muted-foreground'
                    "
                >
                    <component :is="tab.icon" class="size-5" />
                    {{ tab.title }}
                </Link>
            </div>
        </nav>

        <Toaster position="top-center" />
    </div>
</template>
