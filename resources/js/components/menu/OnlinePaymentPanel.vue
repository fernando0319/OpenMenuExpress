<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { Bitcoin, CircleCheck, Copy, CreditCard, TimerOff } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatCents } from '@/lib/money';
import { show as menuShow } from '@/routes/menu';
import { payment as paymentStatus } from '@/routes/menu/orders';

export type OnlinePaymentInfo = {
    method: 'online_pix' | 'online_card' | 'online_bitcoin';
    methodLabel: string;
    provider: string;
    confirmedByStore: boolean;
    status: 'pending' | 'paid' | 'expired' | 'failed' | 'refunded';
    statusLabel: string;
    amountCents: number;
    pixCode: string | null;
    pixQrSvg: string | null;
    checkoutUrl: string | null;
    expiresAt: string | null;
};

/**
 * Paying an order online: the Pix QR code and "copia e cola", or the button to the card / Bitcoin
 * checkout. Confirms by itself (the provider tells the server, and this page asks every few seconds).
 */
const props = defineProps<{
    payment: OnlinePaymentInfo;
    storeSlug: string;
    orderToken: string;
}>();

const copied = ref(false);
const now = ref(Date.now());
let ticker: ReturnType<typeof setInterval> | undefined;
let poller: ReturnType<typeof setInterval> | undefined;

const secondsLeft = computed(() =>
    props.payment.expiresAt
        ? Math.max(
              0,
              Math.floor(
                  (new Date(props.payment.expiresAt).getTime() - now.value) /
                      1000,
              ),
          )
        : null,
);
const countdown = computed(() => {
    if (secondsLeft.value === null) {
        return '';
    }

    const minutes = Math.floor(secondsLeft.value / 60);
    const seconds = String(secondsLeft.value % 60).padStart(2, '0');

    return `${minutes}:${seconds}`;
});

async function check(): Promise<void> {
    try {
        const response = await fetch(
            paymentStatus.url([props.storeSlug, props.orderToken]),
            {
                headers: { Accept: 'application/json' },
            },
        );
        const data = (await response.json()) as {
            payment: OnlinePaymentInfo | null;
        };

        if (data.payment && data.payment.status !== props.payment.status) {
            router.reload({ only: ['order', 'payment'] });
        }
    } catch {
        // Offline for a moment: the next check tries again.
    }
}

async function copy(): Promise<void> {
    if (!props.payment.pixCode) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.payment.pixCode);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2500);
    } catch {
        copied.value = false;
    }
}

onMounted(() => {
    ticker = setInterval(() => (now.value = Date.now()), 1000);

    if (props.payment.status === 'pending') {
        poller = setInterval(check, 4000);
    }
});

onBeforeUnmount(() => {
    clearInterval(ticker);
    clearInterval(poller);
});
</script>

<template>
    <section
        class="flex flex-col items-center gap-4 rounded-3xl bg-white p-5 text-center shadow-soft"
    >
        <template v-if="payment.status === 'pending'">
            <div>
                <h2 class="text-lg font-semibold">
                    {{
                        payment.method === 'online_pix'
                            ? 'Pague com Pix'
                            : `Pague com ${payment.methodLabel.toLowerCase()}`
                    }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{ formatCents(payment.amountCents) }} ·
                    {{
                        payment.confirmedByStore
                            ? 'a loja confere o Pix e confirma o seu pedido em seguida.'
                            : 'o pedido vai para a loja assim que o pagamento for confirmado.'
                    }}
                </p>
            </div>

            <template v-if="payment.method === 'online_pix' && payment.pixCode">
                <div
                    v-if="payment.pixQrSvg"
                    class="size-56 rounded-2xl border bg-white p-2 [&_svg]:size-full"
                    role="img"
                    aria-label="QR Code Pix"
                    v-html="payment.pixQrSvg"
                />
                <p class="text-sm">
                    Abra o app do seu banco, escolha Pix e leia o código, ou
                    copie:
                </p>
                <button
                    type="button"
                    class="flex w-full items-center justify-center gap-2 rounded-2xl bg-linear-to-r from-brand to-brand-accent px-4 py-3.5 font-medium text-white shadow-lg shadow-brand/25"
                    @click="copy"
                >
                    <Copy class="size-4" />
                    {{ copied ? 'Código copiado!' : 'Copiar código Pix' }}
                </button>
                <p
                    class="line-clamp-2 w-full rounded-xl bg-brand-surface px-3 py-2 font-mono text-[11px] break-all text-muted-foreground"
                >
                    {{ payment.pixCode }}
                </p>
            </template>

            <a
                v-else-if="payment.checkoutUrl"
                :href="payment.checkoutUrl"
                class="flex w-full items-center justify-center gap-2 rounded-2xl bg-linear-to-r from-brand to-brand-accent px-4 py-3.5 font-medium text-white shadow-lg shadow-brand/25"
            >
                <Bitcoin
                    v-if="payment.method === 'online_bitcoin'"
                    class="size-5"
                />
                <CreditCard v-else class="size-5" />
                {{
                    payment.method === 'online_bitcoin'
                        ? 'Pagar com Bitcoin'
                        : 'Pagar com cartão'
                }}
            </a>

            <p
                v-if="countdown"
                class="text-sm text-muted-foreground"
                aria-live="polite"
            >
                Tempo para pagar:
                <strong class="text-brand-ink tabular-nums">{{
                    countdown
                }}</strong>
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    payment.confirmedByStore
                        ? 'Pix direto para a conta da loja, sem intermediários.'
                        : `Pagamento processado por ${payment.provider}.`
                }}
            </p>
        </template>

        <template v-else-if="payment.status === 'paid'">
            <CircleCheck class="size-10 text-emerald-600" />
            <p class="font-semibold">Pagamento confirmado</p>
            <p class="text-sm text-muted-foreground">
                {{ payment.methodLabel }} ·
                {{ formatCents(payment.amountCents) }}
            </p>
        </template>

        <template v-else-if="payment.status === 'refunded'">
            <p class="font-semibold">Pagamento estornado</p>
            <p class="text-sm text-muted-foreground">
                O valor volta pelo mesmo meio de pagamento.
            </p>
        </template>

        <template v-else>
            <TimerOff class="size-10 text-muted-foreground" />
            <p class="font-semibold">O tempo para pagar acabou</p>
            <p class="text-sm text-muted-foreground">
                O pedido foi cancelado e nada foi cobrado. Você pode fazer um
                novo pedido.
            </p>
            <Link
                :href="menuShow(storeSlug)"
                class="rounded-2xl bg-brand px-5 py-3 font-medium text-white"
                >Voltar ao cardápio</Link
            >
        </template>
    </section>
</template>
