<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { reactive } from 'vue';
import MessagesController from '@/actions/App/Http/Controllers/StoreSettings/MessagesController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import WhatsAppConnectionCard from '@/components/WhatsAppConnectionCard.vue';
import type { WhatsAppConnection } from '@/components/WhatsAppConnectionCard.vue';
import { edit } from '@/routes/store-settings/messages';

type Template = {
    event: string;
    label: string;
    body: string;
    defaultBody: string;
    isEnabled: boolean;
    isCustom: boolean;
};

type Log = {
    id: number;
    event: string;
    orderNumber: number | null;
    recipient: string;
    status: string;
    error: string | null;
    at: string;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mensagens do WhatsApp', href: edit() }],
    },
});

const props = defineProps<{
    settings: { whatsapp_enabled: boolean };
    driver: string;
    providerReady: boolean;
    connection: WhatsAppConnection | null;
    templates: Template[];
    variables: Record<string, string>;
    recentLogs: Log[];
}>();

const page = usePage();
const storeName = page.props.currentStore?.name ?? 'Sua loja';

const settingsForm = useForm({
    whatsapp_enabled: props.settings.whatsapp_enabled,
});

function saveSettings(): void {
    settingsForm.put(MessagesController.update.url(), { preserveScroll: true });
}

const drafts = reactive(
    Object.fromEntries(
        props.templates.map((template) => [
            template.event,
            { body: template.body, isEnabled: template.isEnabled },
        ]),
    ),
);

const errors = reactive<Record<string, string | undefined>>({});

function saveTemplate(template: Template): void {
    router.put(
        MessagesController.updateTemplate.url(),
        {
            event: template.event,
            body: drafts[template.event].body,
            is_enabled: drafts[template.event].isEnabled,
        },
        {
            preserveScroll: true,
            onError: (bag) => {
                errors[template.event] = bag.body;
            },
            onSuccess: () => {
                errors[template.event] = undefined;
            },
        },
    );
}

function resetTemplate(template: Template): void {
    router.delete(MessagesController.resetTemplate.url(), {
        data: { event: template.event },
        preserveScroll: true,
        onSuccess: () => {
            drafts[template.event].body = template.defaultBody;
        },
    });
}

function insertVariable(event: string, variable: string): void {
    drafts[event].body = `${drafts[event].body}${variable}`;
}

const sample: Record<string, string> = {
    '{nome}': 'Maria',
    '{pedido}': '42',
    '{total}': 'R$ 57,90',
    '{itens}': '1x Pizza grande (Calabresa, Frango)\n2x Refrigerante lata',
    '{entrega}': 'Entrega',
    '{pagamento}': 'PIX',
    '{loja}': storeName,
    '{link_pedido}': 'https://…/pedido',
    '{agendamento}': 'Amanhã às 19:30',
};

function preview(body: string): string {
    return Object.entries(sample).reduce(
        (text, [variable, value]) => text.split(variable).join(value),
        body,
    );
}

const statusLabel: Record<string, string> = {
    pending: 'Enviando',
    sent: 'Enviada',
    failed: 'Falhou',
};

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Mensagens do WhatsApp" />

    <div class="flex max-w-4xl flex-col gap-10 p-4">
        <form class="flex flex-col gap-6" @submit.prevent="saveSettings">
            <Heading
                title="Mensagens do WhatsApp"
                description="O cliente recebe uma mensagem automática quando o pedido muda de situação."
            />

            <p
                v-if="driver === 'log'"
                class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950"
            >
                Modo de desenvolvimento: as mensagens não são enviadas de
                verdade, ficam gravadas no log do sistema
                (<code>storage/logs/laravel.log</code>).
            </p>

            <div class="flex items-center gap-2">
                <Checkbox
                    id="whatsapp_enabled"
                    v-model="settingsForm.whatsapp_enabled"
                />
                <Label for="whatsapp_enabled"
                    >Enviar mensagens automáticas aos clientes</Label
                >
            </div>

            <p
                v-if="settingsForm.whatsapp_enabled && !providerReady"
                class="text-sm text-destructive"
            >
                O WhatsApp ainda não está conectado: as mensagens vão aparecer
                como "Falhou" no histórico.
            </p>

            <div>
                <Button type="submit" :disabled="settingsForm.processing"
                    >Salvar</Button
                >
            </div>
        </form>

        <WhatsAppConnectionCard v-if="connection" :connection="connection" />

        <section class="flex flex-col gap-4">
            <Heading
                variant="small"
                title="Textos das mensagens"
                description="Clique numa variável para inseri-la. A prévia usa dados de exemplo."
            />

            <article
                v-for="template in templates"
                :key="template.event"
                class="grid gap-4 rounded-xl border p-4 md:grid-cols-2"
            >
                <div class="flex flex-col gap-3">
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="font-medium">{{ template.label }}</h3>
                        <Badge v-if="template.isCustom" variant="secondary"
                            >Personalizada</Badge
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <Checkbox
                            :id="`enabled-${template.event}`"
                            v-model="drafts[template.event].isEnabled"
                        />
                        <Label :for="`enabled-${template.event}`"
                            >Enviar esta mensagem</Label
                        >
                    </div>
                    <TextareaField
                        v-model="drafts[template.event].body"
                        :aria-label="`Texto: ${template.label}`"
                        maxlength="1000"
                        class="min-h-28"
                        :disabled="!drafts[template.event].isEnabled"
                    />
                    <InputError :message="errors[template.event]" />
                    <div class="flex flex-wrap gap-1">
                        <Button
                            v-for="(description, variable) in variables"
                            :key="variable"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="h-7 px-2 text-xs"
                            :title="description"
                            :disabled="!drafts[template.event].isEnabled"
                            @click="insertVariable(template.event, variable)"
                        >
                            {{ variable }}
                        </Button>
                    </div>
                    <div class="flex gap-2">
                        <Button size="sm" @click="saveTemplate(template)"
                            >Salvar</Button
                        >
                        <Button
                            v-if="template.isCustom"
                            size="sm"
                            variant="ghost"
                            @click="resetTemplate(template)"
                            >Voltar ao padrão</Button
                        >
                    </div>
                </div>
                <div
                    class="rounded-lg bg-green-50 p-3 text-sm whitespace-pre-line dark:bg-green-950/40"
                    :class="{ 'opacity-40': !drafts[template.event].isEnabled }"
                    aria-label="Prévia da mensagem"
                >
                    {{ preview(drafts[template.event].body) }}
                </div>
            </article>
        </section>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Últimos envios" />
            <p
                v-if="recentLogs.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nenhuma mensagem enviada ainda.
            </p>
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Quando</th>
                            <th class="px-4 py-2 font-medium">Pedido</th>
                            <th class="px-4 py-2 font-medium">Mensagem</th>
                            <th class="px-4 py-2 font-medium">Para</th>
                            <th class="px-4 py-2 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in recentLogs"
                            :key="log.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ dateTime.format(new Date(log.at)) }}
                            </td>
                            <td class="px-4 py-2">
                                {{ log.orderNumber ?? '—' }}
                            </td>
                            <td class="px-4 py-2">{{ log.event }}</td>
                            <td class="px-4 py-2">{{ log.recipient }}</td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        log.status === 'failed'
                                            ? 'destructive'
                                            : 'secondary'
                                    "
                                    :title="log.error ?? undefined"
                                    >{{
                                        statusLabel[log.status] ?? log.status
                                    }}</Badge
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</template>
