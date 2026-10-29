<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, ExternalLink, Unplug } from '@lucide/vue';
import { computed } from 'vue';
import OnlinePaymentsController from '@/actions/App/Http/Controllers/StoreSettings/OnlinePaymentsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/store-settings/online-payments';

type Account = {
    id: number;
    label: string | null;
    methods: string[];
    isActive: boolean;
    lastError: string | null;
    mode: string | null;
    pixKey: string | null;
    clientId: string | null;
    serverUrl: string | null;
    storeId: string | null;
};

type ProviderRow = {
    provider: 'mercadopago' | 'sicoob' | 'btcpay';
    label: string;
    methods: { value: string; label: string }[];
    refundsAutomatically: boolean;
    account: Account | null;
};

const props = defineProps<{
    providers: ProviderRow[];
    mercadoPagoOAuth: boolean;
    expirationMinutes: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pagamento online', href: edit() }],
    },
});

const row = (provider: ProviderRow['provider']) =>
    props.providers.find((item) => item.provider === provider) as ProviderRow;

function toggleMethod(
    account: Account,
    method: string,
    on: boolean | 'indeterminate',
): void {
    const methods =
        on === true
            ? [...new Set([...account.methods, method])]
            : account.methods.filter((item) => item !== method);

    router.put(
        OnlinePaymentsController.update.url(account.id),
        { methods, is_active: account.isActive },
        { preserveScroll: true },
    );
}

function toggleActive(account: Account, on: boolean | 'indeterminate'): void {
    router.put(
        OnlinePaymentsController.update.url(account.id),
        { methods: account.methods, is_active: on === true },
        { preserveScroll: true },
    );
}

function disconnect(provider: ProviderRow): void {
    if (
        provider.account &&
        confirm(
            `Desconectar ${provider.label}? Os clientes deixam de ver esses pagamentos no cardápio.`,
        )
    ) {
        router.delete(
            OnlinePaymentsController.destroy.url(provider.account.id),
            { preserveScroll: true },
        );
    }
}

const mpToken = useForm({ access_token: '' });
const sicoob = useForm({
    client_id: row('sicoob').account?.clientId ?? '',
    pix_key: row('sicoob').account?.pixKey ?? '',
    certificate: null as File | null,
    private_key: null as File | null,
    certificate_password: '',
});

// A .pfx carries the key inside; .pem/.crt/.cer may need the .key file next to it.
const sicoobNeedsKey = computed(() => {
    const name = sicoob.certificate?.name.toLowerCase() ?? '';

    return name !== '' && !name.endsWith('.pfx') && !name.endsWith('.p12');
});
const btcpay = useForm({
    server_url: row('btcpay').account?.serverUrl ?? '',
    api_key: '',
    store_id: row('btcpay').account?.storeId ?? '',
});

function saveSicoob(): void {
    sicoob.post(OnlinePaymentsController.sicoob.url(), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () =>
            sicoob.reset('certificate', 'private_key', 'certificate_password'),
    });
}
</script>

<template>
    <Head title="Pagamento online" />

    <div class="flex max-w-3xl flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Pagamento online"
            description="Pix, cartão e Bitcoin pagos no cardápio, direto na conta da sua loja. O OpenMenuExpress não recebe nem repassa o dinheiro."
        />

        <div class="rounded-2xl border bg-muted/40 p-4 text-sm">
            <ul
                class="flex list-disc flex-col gap-1 pl-5 text-muted-foreground"
            >
                <li>
                    O pedido só chega para a loja (e só imprime) depois que o
                    pagamento é confirmado.
                </li>
                <li>
                    O cliente tem {{ expirationMinutes }} minutos para pagar;
                    depois o pedido é cancelado sozinho.
                </li>
                <li>
                    Se você cancelar um pedido pago, o estorno é automático no
                    Mercado Pago e no Sicoob.
                </li>
                <li>Taxas e prazos são os da sua conta em cada empresa.</li>
            </ul>
        </div>

        <section
            v-for="provider in providers"
            :key="provider.provider"
            class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-semibold">{{ provider.label }}</h2>
                <Badge
                    v-for="method in provider.methods"
                    :key="method.value"
                    variant="secondary"
                    >{{ method.label }}</Badge
                >
                <span
                    v-if="provider.account"
                    class="ml-auto flex items-center gap-1 text-sm text-emerald-700 dark:text-emerald-300"
                >
                    <CheckCircle2 class="size-4" /> Conectado
                </span>
            </div>

            <template v-if="provider.account">
                <p class="text-sm text-muted-foreground">
                    {{ provider.account.label }}
                    <template v-if="provider.account.mode === 'oauth'">
                        · conectado pela sua conta</template
                    >
                    <template v-else-if="provider.account.mode === 'token'">
                        · conectado por token</template
                    >
                </p>
                <p
                    v-if="provider.account.lastError"
                    class="flex items-start gap-2 rounded-lg bg-amber-500/10 p-2 text-sm text-amber-800 dark:text-amber-300"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ provider.account.lastError }}
                </p>
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    <Label class="flex items-center gap-2 font-normal">
                        <Checkbox
                            :model-value="provider.account.isActive"
                            @update:model-value="
                                toggleActive(provider.account, $event)
                            "
                        />
                        Ativo no cardápio
                    </Label>
                    <Label
                        v-for="method in provider.methods"
                        :key="method.value"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="
                                provider.account.methods.includes(method.value)
                            "
                            :disabled="!provider.account.isActive"
                            @update:model-value="
                                toggleMethod(
                                    provider.account,
                                    method.value,
                                    $event,
                                )
                            "
                        />
                        Receber {{ method.label }}
                    </Label>
                </div>
                <p class="text-xs text-muted-foreground">
                    Se duas contas recebem o mesmo meio (ex.: Pix no Sicoob e no
                    Mercado Pago), vale a última que você marcar.
                    <template v-if="!provider.refundsAutomatically">
                        Estornos de pedidos cancelados são feitos por você no
                        BTCPay.</template
                    >
                </p>
            </template>

            <!-- Mercado Pago -->
            <template v-if="provider.provider === 'mercadopago'">
                <div
                    v-if="mercadoPagoOAuth"
                    class="flex flex-wrap items-center gap-3"
                >
                    <Button as-child>
                        <a
                            :href="
                                OnlinePaymentsController.connectMercadoPago.url()
                            "
                        >
                            <ExternalLink />
                            {{ provider.account ? 'Reconectar' : 'Conectar' }}
                            minha conta Mercado Pago
                        </a>
                    </Button>
                    <span class="text-xs text-muted-foreground"
                        >Você entra na sua conta e autoriza; não precisa copiar
                        chaves.</span
                    >
                </div>
                <form
                    v-else
                    class="grid gap-2"
                    @submit.prevent="
                        mpToken.post(
                            OnlinePaymentsController.mercadoPagoToken.url(),
                            {
                                preserveScroll: true,
                                onSuccess: () => mpToken.reset(),
                            },
                        )
                    "
                >
                    <Label for="mp-token">Access Token de produção</Label>
                    <div class="flex flex-wrap gap-2">
                        <Input
                            id="mp-token"
                            v-model="mpToken.access_token"
                            class="min-w-64 flex-1 font-mono"
                            placeholder="APP_USR-..."
                            autocomplete="off"
                        />
                        <Button type="submit" :disabled="mpToken.processing">{{
                            provider.account ? 'Trocar token' : 'Conectar'
                        }}</Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        No portal Mercado Pago Developers: Suas integrações ›
                        sua aplicação › Credenciais de produção. A conta precisa
                        ter uma chave Pix cadastrada para receber Pix.
                    </p>
                    <InputError :message="mpToken.errors.access_token" />
                </form>
            </template>

            <!-- Sicoob -->
            <form
                v-else-if="provider.provider === 'sicoob'"
                class="grid gap-3 sm:grid-cols-2"
                @submit.prevent="saveSicoob"
            >
                <div class="grid gap-2">
                    <Label for="sicoob-client">Client ID</Label>
                    <Input
                        id="sicoob-client"
                        v-model="sicoob.client_id"
                        autocomplete="off"
                    />
                    <InputError :message="sicoob.errors.client_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="sicoob-key">Chave Pix que recebe</Label>
                    <Input
                        id="sicoob-key"
                        v-model="sicoob.pix_key"
                        placeholder="CNPJ, e-mail, telefone ou aleatória"
                    />
                    <InputError :message="sicoob.errors.pix_key" />
                </div>
                <div class="grid gap-2">
                    <Label for="sicoob-cert">Certificado digital</Label>
                    <input
                        id="sicoob-cert"
                        type="file"
                        accept=".pfx,.p12,.pem,.crt,.cer"
                        class="text-sm"
                        @change="
                            sicoob.certificate =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        .pfx ou .p12 (recomendado), ou .pem, .crt ou .cer com a
                        chave privada.
                        <template v-if="provider.account"
                            >Envie de novo só para trocar.</template
                        >
                    </p>
                    <InputError :message="sicoob.errors.certificate" />
                </div>
                <div v-if="sicoobNeedsKey" class="grid gap-2">
                    <Label for="sicoob-key-file">Chave privada (.key)</Label>
                    <input
                        id="sicoob-key-file"
                        type="file"
                        accept=".key,.pem"
                        class="text-sm"
                        @change="
                            sicoob.private_key =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Dispensável se a chave já estiver dentro do mesmo
                        arquivo .pem.
                    </p>
                    <InputError :message="sicoob.errors.private_key" />
                </div>
                <div class="grid gap-2">
                    <Label for="sicoob-pass">{{
                        sicoobNeedsKey
                            ? 'Senha da chave (se tiver)'
                            : 'Senha do certificado'
                    }}</Label>
                    <Input
                        id="sicoob-pass"
                        v-model="sicoob.certificate_password"
                        type="password"
                        autocomplete="off"
                    />
                </div>
                <p class="text-xs text-muted-foreground sm:col-span-2">
                    Gere o Client ID e o certificado no portal de
                    desenvolvedores do Sicoob (API Pix), com a sua cooperativa.
                    O certificado fica guardado criptografado.
                </p>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="sicoob.processing"
                        >{{
                            provider.account ? 'Atualizar' : 'Conectar'
                        }}
                        Sicoob</Button
                    >
                </div>
            </form>

            <!-- BTCPay -->
            <form
                v-else
                class="grid gap-3 sm:grid-cols-2"
                @submit.prevent="
                    btcpay.post(OnlinePaymentsController.btcpay.url(), {
                        preserveScroll: true,
                        onSuccess: () => btcpay.reset('api_key'),
                    })
                "
            >
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="btc-url">Endereço do servidor</Label>
                    <Input
                        id="btc-url"
                        v-model="btcpay.server_url"
                        placeholder="https://btcpay.sualoja.com.br"
                    />
                    <InputError :message="btcpay.errors.server_url" />
                </div>
                <div class="grid gap-2">
                    <Label for="btc-store">Store ID</Label>
                    <Input
                        id="btc-store"
                        v-model="btcpay.store_id"
                        autocomplete="off"
                    />
                    <InputError :message="btcpay.errors.store_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="btc-key">Chave de API</Label>
                    <Input
                        id="btc-key"
                        v-model="btcpay.api_key"
                        type="password"
                        autocomplete="off"
                        :placeholder="
                            provider.account
                                ? 'Mantida; preencha para trocar'
                                : ''
                        "
                    />
                    <InputError :message="btcpay.errors.api_key" />
                </div>
                <p class="text-xs text-muted-foreground sm:col-span-2">
                    No BTCPay: Conta › API Keys, com as permissões de criar e
                    ver faturas, ver a loja e gerenciar webhooks.
                </p>
                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="btcpay.processing"
                        >{{
                            provider.account ? 'Atualizar' : 'Conectar'
                        }}
                        BTCPay</Button
                    >
                </div>
            </form>

            <div v-if="provider.account" class="border-t pt-3">
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="disconnect(provider)"
                    ><Unplug /> Desconectar</Button
                >
            </div>
        </section>
    </div>
</template>
