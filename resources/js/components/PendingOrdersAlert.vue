<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { BellRing, Volume2, VolumeX } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PendingOrdersController from '@/actions/App/Http/Controllers/Orders/PendingOrdersController';
import { Button } from '@/components/ui/button';
import { notifyNewOrders } from '@/lib/desktop';
import { formatCents } from '@/lib/money';
import { playNewOrderChime, soundBlocked, unlockSound } from '@/lib/sound';
import { index as ordersIndex, show as orderShow } from '@/routes/orders';

type Oldest = {
    id: number;
    number: number;
    customer_name: string;
    total_cents: number;
};

const POLL_MS = 8_000;
const RING_MS = 6_000;
const MUTE_KEY = 'mx-order-sound-muted';

const page = usePage();
const enabled = computed(() => page.props.currentStore?.canViewOrders === true);

const count = ref(0);
const oldest = ref<Oldest | null>(null);
const muted = ref(readMuted());
const blocked = ref(false);

let pollTimer: ReturnType<typeof setInterval> | undefined;
let ringTimer: ReturnType<typeof setInterval> | undefined;
let stopNavigate: (() => void) | undefined;

function readMuted(): boolean {
    try {
        return window.localStorage.getItem(MUTE_KEY) === '1';
    } catch {
        return false;
    }
}

function toggleMute(): void {
    muted.value = !muted.value;

    try {
        window.localStorage.setItem(MUTE_KEY, muted.value ? '1' : '0');
    } catch {
        // Private mode: the choice lasts until the page is closed.
    }

    if (!muted.value) {
        void enableSound();
    }
}

async function enableSound(): Promise<void> {
    await unlockSound();
    blocked.value = soundBlocked();
    ring();
}

async function refresh(): Promise<void> {
    if (!enabled.value) {
        return;
    }

    try {
        const response = await fetch(PendingOrdersController.url(), {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            return;
        }

        const data = (await response.json()) as {
            count: number;
            oldest: Oldest | null;
        };
        const grew = data.count > count.value;
        count.value = data.count;
        oldest.value = data.oldest;

        if (grew) {
            ring();

            // In the Windows app: a system notification, even with the window hidden.
            if (data.oldest) {
                notifyNewOrders(
                    data.count === 1
                        ? 'Novo pedido aguardando aceite'
                        : `${data.count} pedidos aguardando aceite`,
                    `Nº ${data.oldest.number} · ${data.oldest.customer_name} · ${formatCents(data.oldest.total_cents)}`,
                );
            }
        }
    } catch {
        // Offline for a moment: the next poll catches up.
    }
}

function ring(): void {
    if (count.value === 0 || muted.value) {
        return;
    }

    blocked.value = soundBlocked();

    if (!blocked.value) {
        playNewOrderChime();
    }
}

onMounted(() => {
    void refresh();
    pollTimer = setInterval(() => void refresh(), POLL_MS);
    // Keeps ringing until every order is accepted (or the sound is muted).
    ringTimer = setInterval(ring, RING_MS);
    // Accepting an order elsewhere in the panel stops the alarm right away.
    stopNavigate = router.on('finish', () => void refresh());
});

onBeforeUnmount(() => {
    clearInterval(pollTimer);
    clearInterval(ringTimer);
    stopNavigate?.();
});
</script>

<template>
    <div
        v-if="enabled && count > 0"
        role="alert"
        class="fixed right-4 bottom-4 z-50 flex w-[min(22rem,calc(100vw-2rem))] flex-col gap-3 rounded-2xl border border-amber-400/60 bg-background p-4 shadow-2xl"
    >
        <div class="flex items-start gap-3">
            <span
                class="flex size-10 shrink-0 animate-pulse items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400"
            >
                <BellRing class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold">
                    {{
                        count === 1
                            ? '1 pedido aguardando aceite'
                            : `${count} pedidos aguardando aceite`
                    }}
                </p>
                <p v-if="oldest" class="truncate text-sm text-muted-foreground">
                    Nº {{ oldest.number }} · {{ oldest.customer_name }} ·
                    {{ formatCents(oldest.total_cents) }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0"
                :aria-label="muted ? 'Ligar o som' : 'Silenciar o som'"
                :title="muted ? 'Ligar o som' : 'Silenciar o som'"
                @click="toggleMute"
            >
                <VolumeX v-if="muted" />
                <Volume2 v-else />
            </Button>
        </div>
        <button
            v-if="blocked && !muted"
            type="button"
            class="rounded-xl bg-amber-500/15 px-3 py-2 text-left text-sm font-medium text-amber-800 dark:text-amber-300"
            @click="enableSound"
        >
            O navegador bloqueou o som. Clique aqui para ativar o alerta.
        </button>
        <div class="flex gap-2">
            <Button as-child class="flex-1">
                <Link
                    :href="
                        count === 1 && oldest
                            ? orderShow(oldest.id)
                            : ordersIndex()
                    "
                    >{{ count === 1 ? 'Ver pedido' : 'Ver pedidos' }}</Link
                >
            </Button>
        </div>
    </div>
</template>
