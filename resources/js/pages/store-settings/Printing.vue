<script setup lang="ts">
import { Head, router, useForm, usePoll } from '@inertiajs/vue3';
import {
    Download,
    Monitor,
    Plus,
    Printer as PrinterIcon,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import PrintingController from '@/actions/App/Http/Controllers/StoreSettings/PrintingController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/store-settings/printing';

type ConfiguredPrinter = {
    id: number;
    systemName: string;
    label: string;
    paperWidth: number;
    copies: number;
    font: string;
    isActive: boolean;
};

type Agent = {
    id: number;
    name: string;
    platform: string | null;
    version: string | null;
    online: boolean;
    lastSeenAt: string | null;
    availablePrinters: string[];
    printers: ConfiguredPrinter[];
};

type Job = {
    id: number;
    printer: string;
    orderNumber: number | null;
    kind: string;
    status: string;
    error: string | null;
    attempts: number;
    at: string;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Impressão', href: edit() }],
    },
});

const props = defineProps<{
    autoPrint: string;
    autoPrintOptions: { value: string; label: string }[];
    pairingCode: string | null;
    serverUrl: string;
    fontOptions: { value: string; label: string }[];
    downloads: { label: string; url: string }[];
    agents: Agent[];
    recentJobs: Job[];
}>();

// Agent status and the job list refresh on their own.
usePoll(5_000, { only: ['agents', 'recentJobs'] });

const autoPrintForm = useForm({ auto_print: props.autoPrint });

function saveAutoPrint(): void {
    autoPrintForm.put(PrintingController.update.url(), {
        preserveScroll: true,
    });
}

function requestPairingCode(): void {
    router.post(
        PrintingController.pairingCode.url(),
        {},
        { preserveScroll: true },
    );
}

const printerDialogOpen = ref(false);
const printerForm = useForm({
    print_agent_id: 0,
    system_name: '',
    label: '',
    paper_width: 80,
    copies: 1,
    font: 'large',
    is_active: true,
});

function configurePrinter(agent: Agent, systemName: string): void {
    const existing = agent.printers.find(
        (printer) => printer.systemName === systemName,
    );

    printerForm.print_agent_id = agent.id;
    printerForm.system_name = systemName;
    printerForm.label =
        existing?.label ?? (agent.printers.length === 0 ? 'Balcão' : 'Cozinha');
    printerForm.paper_width = existing?.paperWidth ?? 80;
    printerForm.copies = existing?.copies ?? 1;
    printerForm.font = existing?.font ?? 'large';
    printerForm.is_active = existing?.isActive ?? true;
    printerForm.clearErrors();
    printerDialogOpen.value = true;
}

function savePrinter(): void {
    printerForm.post(PrintingController.savePrinter.url(), {
        preserveScroll: true,
        onSuccess: () => {
            printerDialogOpen.value = false;
        },
    });
}

function testPrinter(printer: ConfiguredPrinter): void {
    router.post(
        PrintingController.testPrinter.url(printer.id),
        {},
        { preserveScroll: true },
    );
}

function removePrinter(printer: ConfiguredPrinter): void {
    if (!confirm(`Parar de usar a impressora "${printer.label}"?`)) {
        return;
    }

    router.delete(PrintingController.destroyPrinter.url(printer.id), {
        preserveScroll: true,
    });
}

function removeAgent(agent: Agent): void {
    if (
        !confirm(
            `Desvincular o computador "${agent.name}"? O agente nele para de imprimir.`,
        )
    ) {
        return;
    }

    router.delete(PrintingController.destroyAgent.url(agent.id), {
        preserveScroll: true,
    });
}

function isConfigured(agent: Agent, systemName: string): boolean {
    return agent.printers.some((printer) => printer.systemName === systemName);
}

const jobStatus: Record<
    string,
    { label: string; tone: 'default' | 'secondary' | 'destructive' | 'outline' }
> = {
    pending: { label: 'Na fila', tone: 'outline' },
    sent: { label: 'Imprimindo', tone: 'secondary' },
    printed: { label: 'Impresso', tone: 'default' },
    failed: { label: 'Falhou', tone: 'destructive' },
};

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head title="Impressão" />

    <div class="flex max-w-4xl flex-col gap-10 p-4">
        <section class="flex flex-col gap-4">
            <Heading
                title="Impressão automática"
                description="O agente de impressão roda no computador da loja e imprime os pedidos nas impressoras térmicas."
            />

            <form class="flex flex-col gap-3" @submit.prevent="saveAutoPrint">
                <label
                    v-for="option in autoPrintOptions"
                    :key="option.value"
                    class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 text-sm has-[:checked]:border-primary"
                >
                    <input
                        v-model="autoPrintForm.auto_print"
                        type="radio"
                        name="auto_print"
                        :value="option.value"
                    />
                    {{ option.label }}
                </label>
                <div>
                    <Button type="submit" :disabled="autoPrintForm.processing"
                        >Salvar</Button
                    >
                </div>
            </form>
        </section>

        <section class="flex flex-col gap-4 rounded-xl border p-4">
            <Heading
                variant="small"
                title="1. Instale o programa no computador da loja"
                description="O OpenMenuExpress Impressão é gratuito e fica aberto em segundo plano, imprimindo os pedidos assim que chegam."
            />
            <div v-if="downloads.length > 0" class="flex flex-wrap gap-2">
                <Button
                    v-for="download in downloads"
                    :key="download.url"
                    as="a"
                    :href="download.url"
                    download
                    :variant="
                        download.url.endsWith('.exe') ? 'default' : 'outline'
                    "
                >
                    <Download />
                    {{ download.label }}
                </Button>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                O download ainda não está disponível neste servidor. Fale com o
                suporte.
            </p>
            <ol
                class="list-decimal space-y-1 pl-5 text-sm text-muted-foreground"
            >
                <li>
                    Instale a impressora térmica no Windows normalmente (driver
                    do fabricante ou "Generic / Text Only").
                </li>
                <li>
                    Salve o programa numa pasta fixa, por exemplo
                    <code>C:\MenuExpress</code>, e abra.
                </li>
                <li>
                    Se ele pedir o endereço, informe
                    <code class="font-medium text-foreground">{{
                        serverUrl
                    }}</code
                    >. Depois, clique em
                    <strong>Vincular computador</strong> abaixo e digite o
                    código no programa.
                </li>
                <li>
                    Para abrir junto com o Windows: tecla Windows + R, digite
                    <code>shell:startup</code> e coloque ali um atalho do
                    programa.
                </li>
            </ol>
            <p class="text-xs text-muted-foreground">
                Se o Windows mostrar "O Windows protegeu o computador", clique
                em
                <strong>Mais informações → Executar assim mesmo</strong>.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <div class="flex items-start justify-between gap-4">
                <Heading
                    variant="small"
                    title="2. Computadores"
                    description="Cada computador com o agente aparece aqui com as impressoras dele."
                />
                <Button variant="outline" @click="requestPairingCode">
                    <Plus />
                    Vincular computador
                </Button>
            </div>

            <div
                v-if="pairingCode"
                class="flex flex-col items-center gap-2 rounded-xl border border-primary p-6 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    No computador da loja, abra o
                    <strong>OpenMenuExpress Impressão</strong> e digite o
                    código:
                </p>
                <p class="font-mono text-4xl font-semibold tracking-[0.3em]">
                    {{ pairingCode }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Vale por 10 minutos e funciona uma vez. Esta página atualiza
                    sozinha quando o computador conectar.
                </p>
            </div>

            <p
                v-if="agents.length === 0 && !pairingCode"
                class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Nenhum computador vinculado ainda.
            </p>

            <article
                v-for="agent in agents"
                :key="agent.id"
                class="flex flex-col gap-4 rounded-xl border p-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Monitor class="size-4" />
                    <h3 class="font-medium">{{ agent.name }}</h3>
                    <Badge :variant="agent.online ? 'default' : 'secondary'">
                        {{ agent.online ? 'Online' : 'Offline' }}
                    </Badge>
                    <span class="text-xs text-muted-foreground">
                        <template v-if="agent.version"
                            >v{{ agent.version }} ·
                        </template>
                        <template v-if="agent.lastSeenAt"
                            >visto em
                            {{
                                dateTime.format(new Date(agent.lastSeenAt))
                            }}</template
                        >
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="ml-auto text-destructive"
                        @click="removeAgent(agent)"
                    >
                        Desvincular
                    </Button>
                </div>

                <ul
                    v-if="agent.printers.length > 0"
                    class="flex flex-col divide-y rounded-lg border"
                >
                    <li
                        v-for="printer in agent.printers"
                        :key="printer.id"
                        class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
                    >
                        <PrinterIcon class="size-4" />
                        <span class="font-medium">{{ printer.label }}</span>
                        <span class="text-muted-foreground"
                            >{{ printer.systemName }} ·
                            {{ printer.paperWidth }} mm · {{ printer.copies }}
                            {{ printer.copies > 1 ? 'vias' : 'via' }} · letra
                            {{
                                fontOptions
                                    .find(
                                        (option) =>
                                            option.value === printer.font,
                                    )
                                    ?.label.toLowerCase()
                            }}</span
                        >
                        <Badge v-if="!printer.isActive" variant="secondary"
                            >Pausada</Badge
                        >
                        <span class="ml-auto flex gap-1">
                            <Button
                                size="sm"
                                variant="outline"
                                @click="testPrinter(printer)"
                                >Testar</Button
                            >
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="
                                    configurePrinter(agent, printer.systemName)
                                "
                                >Editar</Button
                            >
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="`Remover ${printer.label}`"
                                @click="removePrinter(printer)"
                            >
                                <Trash2 />
                            </Button>
                        </span>
                    </li>
                </ul>

                <div class="flex flex-col gap-2">
                    <p class="text-xs text-muted-foreground">
                        Impressoras encontradas neste computador:
                    </p>
                    <p
                        v-if="agent.availablePrinters.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nenhuma ainda. Verifique se a impressora está instalada
                        no computador e se o agente está aberto.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="systemName in agent.availablePrinters.filter(
                                (name) => !isConfigured(agent, name),
                            )"
                            :key="systemName"
                            size="sm"
                            variant="outline"
                            @click="configurePrinter(agent, systemName)"
                        >
                            <Plus />
                            {{ systemName }}
                        </Button>
                    </div>
                </div>
            </article>
        </section>

        <section class="flex flex-col gap-4">
            <Heading variant="small" title="Últimas impressões" />
            <p
                v-if="recentJobs.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nada impresso ainda.
            </p>
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2 font-medium">Quando</th>
                            <th class="px-4 py-2 font-medium">O quê</th>
                            <th class="px-4 py-2 font-medium">Impressora</th>
                            <th class="px-4 py-2 font-medium">Situação</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="job in recentJobs"
                            :key="job.id"
                            class="border-t"
                        >
                            <td class="px-4 py-2 text-muted-foreground">
                                {{ dateTime.format(new Date(job.at)) }}
                            </td>
                            <td class="px-4 py-2">
                                {{
                                    job.kind === 'test'
                                        ? 'Teste'
                                        : `Pedido nº ${job.orderNumber ?? '—'}`
                                }}
                            </td>
                            <td class="px-4 py-2">{{ job.printer }}</td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        jobStatus[job.status]?.tone ??
                                        'secondary'
                                    "
                                    :title="job.error ?? undefined"
                                    >{{
                                        jobStatus[job.status]?.label ??
                                        job.status
                                    }}</Badge
                                >
                                <span
                                    v-if="job.error"
                                    class="ml-2 text-xs text-destructive"
                                    >{{ job.error }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <Dialog v-model:open="printerDialogOpen">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="savePrinter">
                <DialogHeader>
                    <DialogTitle>{{ printerForm.system_name }}</DialogTitle>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="printer-label">Nome no painel</Label>
                    <Input
                        id="printer-label"
                        v-model="printerForm.label"
                        required
                        placeholder="Ex.: Cozinha, Balcão"
                    />
                    <InputError :message="printerForm.errors.label" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="paper">Papel</Label>
                        <NativeSelect
                            id="paper"
                            v-model.number="printerForm.paper_width"
                        >
                            <option :value="80">80 mm</option>
                            <option :value="58">58 mm</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2">
                        <Label for="copies">Vias por pedido</Label>
                        <NativeSelect
                            id="copies"
                            v-model.number="printerForm.copies"
                        >
                            <option :value="1">1</option>
                            <option :value="2">2</option>
                            <option :value="3">3</option>
                        </NativeSelect>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="font">Tamanho da letra</Label>
                    <NativeSelect id="font" v-model="printerForm.font">
                        <option
                            v-for="option in fontOptions"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                    <p class="text-xs text-muted-foreground">
                        Grande dobra a altura das letras. Extra grande dobra
                        também a largura (cabem metade dos caracteres por
                        linha). Use "Testar" para ver como fica.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="printer-active"
                        v-model="printerForm.is_active"
                    />
                    <Label for="printer-active"
                        >Imprimir os pedidos nesta impressora</Label
                    >
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="printerForm.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
