<script setup lang="ts">
import { router, useForm, usePoll } from '@inertiajs/vue3';
import { CircleCheck, Loader2, QrCode, Smartphone } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import WhatsAppConnectionController from '@/actions/App/Http/Controllers/StoreSettings/WhatsAppConnectionController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type WhatsAppConnection = {
    state: 'open' | 'connecting' | 'close' | 'not_found' | 'error';
    label: string;
    error?: string;
    qr?: string | null;
    pairingCode?: string | null;
    number?: string | null;
    profileName?: string | null;
};

const props = defineProps<{
    connection: WhatsAppConnection;
}>();

const isOpen = computed(() => props.connection.state === 'open');
const hasPrompt = computed(
    () => Boolean(props.connection.qr) || Boolean(props.connection.pairingCode),
);
const usePairingCode = ref(false);

const form = useForm({ phone: '' });

// While a QR code is on screen, check every 3 s whether the phone has linked.
const { start, stop } = usePoll(
    3_000,
    { only: ['connection', 'providerReady'] },
    { autoStart: false },
);

watch(
    () => [props.connection.state, hasPrompt.value] as const,
    ([state, prompt]) => {
        if (state === 'connecting' || prompt) {
            start();
        } else {
            stop();
        }
    },
    { immediate: true },
);

function connect(): void {
    form.transform((data) => ({
        phone: usePairingCode.value ? data.phone : null,
    })).post(WhatsAppConnectionController.connect.url(), {
        preserveScroll: true,
    });
}

function disconnect(): void {
    if (
        !confirm(
            'Desconectar o WhatsApp? As mensagens automáticas param até você conectar de novo.',
        )
    ) {
        return;
    }

    router.delete(WhatsAppConnectionController.disconnect.url(), {
        preserveScroll: true,
    });
}

function formatPhone(digits: string): string {
    const local = digits.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : digits;
}

function connectionError(): string | undefined {
    return (form.errors as Record<string, string | undefined>).connection;
}
</script>

<template>
    <section class="flex flex-col gap-4 rounded-xl border p-4">
        <div class="flex items-center justify-between gap-2">
            <h3 class="font-medium">Conexão do WhatsApp</h3>
            <Badge :variant="isOpen ? 'default' : 'secondary'">
                <CircleCheck v-if="isOpen" class="size-3.5" />
                {{ connection.label }}
            </Badge>
        </div>

        <p v-if="connection.state === 'error'" class="text-sm text-destructive">
            {{ connection.error }}
        </p>

        <template v-if="isOpen">
            <p class="text-sm">
                Mensagens saem pelo número
                <strong v-if="connection.number">{{
                    formatPhone(connection.number)
                }}</strong>
                <template v-if="connection.profileName">
                    ({{ connection.profileName }})</template
                >.
            </p>
            <div>
                <Button variant="outline" @click="disconnect"
                    >Desconectar</Button
                >
            </div>
        </template>

        <template v-else-if="hasPrompt">
            <div class="flex flex-col items-center gap-3 text-center">
                <img
                    v-if="connection.qr"
                    :src="connection.qr"
                    alt="QR Code para conectar o WhatsApp"
                    class="size-64 rounded-lg border bg-white p-2"
                />
                <p v-if="connection.pairingCode" class="text-sm">
                    Código de pareamento:
                    <strong class="font-mono text-lg tracking-widest">{{
                        connection.pairingCode
                    }}</strong>
                </p>
                <ol class="max-w-sm text-left text-sm text-muted-foreground">
                    <li>1. Abra o WhatsApp no celular da loja.</li>
                    <li>2. Toque em <strong>Aparelhos conectados</strong>.</li>
                    <li>
                        3.
                        <template v-if="connection.qr"
                            >Toque em <strong>Conectar um aparelho</strong> e
                            aponte a câmera para o QR Code.</template
                        >
                        <template v-else
                            >Escolha
                            <strong>Conectar com número de telefone</strong> e
                            digite o código acima.</template
                        >
                    </li>
                </ol>
                <p
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <Loader2 class="size-3.5 animate-spin" />
                    Aguardando a conexão… o código expira em menos de um minuto.
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="form.processing"
                    @click="connect"
                >
                    Gerar novo código
                </Button>
            </div>
        </template>

        <form v-else class="flex flex-col gap-4" @submit.prevent="connect">
            <p class="text-sm text-muted-foreground">
                Conecte o WhatsApp da loja para enviar as mensagens automáticas.
                Use um número que fique sempre com o celular ligado.
            </p>

            <div class="flex gap-2">
                <label
                    class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm has-[:checked]:border-primary"
                >
                    <input
                        v-model="usePairingCode"
                        type="radio"
                        :value="false"
                    />
                    <QrCode class="size-4" /> Ler QR Code
                </label>
                <label
                    class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm has-[:checked]:border-primary"
                >
                    <input
                        v-model="usePairingCode"
                        type="radio"
                        :value="true"
                    />
                    <Smartphone class="size-4" /> Código pelo número
                </label>
            </div>

            <div v-if="usePairingCode" class="grid max-w-xs gap-2">
                <Label for="whatsapp-phone">Número do WhatsApp da loja</Label>
                <Input
                    id="whatsapp-phone"
                    v-model="form.phone"
                    type="tel"
                    placeholder="(11) 99999-0000"
                    required
                />
                <InputError :message="form.errors.phone" />
            </div>

            <InputError :message="connectionError()" />

            <div>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Conectar WhatsApp
                </Button>
            </div>
        </form>
    </section>
</template>
