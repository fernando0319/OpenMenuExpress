<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import { computed, ref } from 'vue';
import CourierAdminController from '@/actions/App/Http/Controllers/Admin/CourierAdminController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCents } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index as networkIndex } from '@/routes/admin/network';
import {
    document as documentRoute,
    index,
} from '@/routes/admin/network/couriers';

const props = defineProps<{
    courier: {
        id: number;
        name: string;
        email: string | null;
        cpf: string;
        phone: string;
        city: string;
        state: string;
        vehicle: string;
        pixKeyType: string;
        pixKey: string;
        status: 'pending' | 'approved' | 'rejected' | 'blocked';
        statusLabel: string;
        statusReason: string | null;
        reviewedAt: string | null;
        online: boolean;
        lastSeenAt: string | null;
        createdAt: string | null;
        hasDocument: boolean;
        documentIsPdf: boolean;
    };
    stats: { runs: number; earnedCents: number; cancelledAfterAccept: number };
    runs: {
        id: number;
        storeName: string;
        statusLabel: string;
        courierCents: number;
        at: string | null;
    }[];
    payouts: {
        id: number;
        period: string;
        amountCents: number;
        status: 'pending' | 'paid';
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Rede de entregadores', href: networkIndex() },
            { title: 'Entregadores', href: index() },
        ],
    },
});

const documentUrl = computed(() => documentRoute.url(props.courier.id));
const cpf = computed(() =>
    props.courier.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4'),
);
const phone = computed(() => {
    const local = props.courier.phone.replace(/^55/, '');

    return `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`;
});

const form = useForm({ status: 'approved', reason: '' });
const dialogOpen = ref(false);

function approve(): void {
    form.status = 'approved';
    form.reason = '';
    form.post(CourierAdminController.review.url(props.courier.id), {
        preserveScroll: true,
    });
}

function askReason(status: 'rejected' | 'blocked'): void {
    form.clearErrors();
    form.status = status;
    form.reason = '';
    dialogOpen.value = true;
}

function confirmReason(): void {
    form.post(CourierAdminController.review.url(props.courier.id), {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function when(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '—';
}
</script>

<template>
    <Head :title="courier.name" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="courier.name"
                :description="`${courier.city}/${courier.state} · ${courier.vehicle}`"
            />
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="courier.status !== 'approved'"
                    :disabled="form.processing"
                    @click="approve"
                >
                    {{
                        courier.status === 'pending'
                            ? 'Aprovar'
                            : 'Aprovar de novo'
                    }}
                </Button>
                <Button
                    v-if="courier.status === 'pending'"
                    variant="outline"
                    :disabled="form.processing"
                    @click="askReason('rejected')"
                    >Recusar</Button
                >
                <Button
                    v-if="courier.status === 'approved'"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="askReason('blocked')"
                    >Bloquear</Button
                >
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                class="flex flex-col gap-2 rounded-2xl border bg-card p-4 text-sm shadow-xs"
            >
                <div class="flex items-center gap-2">
                    <Badge
                        :variant="
                            courier.status === 'approved'
                                ? 'secondary'
                                : courier.status === 'pending'
                                  ? 'outline'
                                  : 'destructive'
                        "
                        >{{ courier.statusLabel }}</Badge
                    >
                    <span v-if="courier.online" class="text-xs text-emerald-600"
                        >● online</span
                    >
                </div>
                <p v-if="courier.statusReason" class="text-muted-foreground">
                    Motivo: {{ courier.statusReason }}
                </p>
                <dl class="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1.5">
                    <dt class="text-muted-foreground">CPF</dt>
                    <dd>{{ cpf }}</dd>
                    <dt class="text-muted-foreground">E-mail</dt>
                    <dd class="break-all">{{ courier.email ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Celular</dt>
                    <dd>
                        <a
                            :href="`https://wa.me/${courier.phone}`"
                            target="_blank"
                            rel="noopener"
                            class="text-primary underline-offset-4 hover:underline"
                            >{{ phone }}</a
                        >
                    </dd>
                    <dt class="text-muted-foreground">
                        PIX ({{ courier.pixKeyType }})
                    </dt>
                    <dd class="break-all">{{ courier.pixKey }}</dd>
                    <dt class="text-muted-foreground">Cadastro</dt>
                    <dd>{{ when(courier.createdAt) }}</dd>
                    <dt class="text-muted-foreground">Analisado</dt>
                    <dd>{{ when(courier.reviewedAt) }}</dd>
                    <dt class="text-muted-foreground">Última posição</dt>
                    <dd>{{ when(courier.lastSeenAt) }}</dd>
                </dl>
            </section>

            <section
                class="flex flex-col gap-2 rounded-2xl border bg-card p-4 text-sm shadow-xs"
            >
                <div class="flex items-center justify-between gap-2">
                    <h3 class="font-medium">Documento</h3>
                    <a
                        v-if="courier.hasDocument"
                        :href="documentUrl"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-xs text-primary"
                        >Abrir <ExternalLink class="size-3"
                    /></a>
                </div>
                <p v-if="!courier.hasDocument" class="text-muted-foreground">
                    Sem documento enviado.
                </p>
                <p
                    v-else-if="courier.documentIsPdf"
                    class="text-muted-foreground"
                >
                    Arquivo PDF: use "Abrir".
                </p>
                <img
                    v-else
                    :src="documentUrl"
                    alt="Documento do entregador"
                    class="max-h-96 w-full rounded-lg border object-contain"
                />
                <p class="text-xs text-muted-foreground">
                    Confira se o nome e o CPF batem com o cadastro e se a CNH
                    está válida para o veículo.
                </p>
            </section>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-2xl border bg-card p-4 shadow-xs">
                <p class="text-xs text-muted-foreground">Entregas feitas</p>
                <p class="text-xl font-semibold">{{ stats.runs }}</p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-xs">
                <p class="text-xs text-muted-foreground">Ganhos totais</p>
                <p class="text-xl font-semibold">
                    {{ formatCents(stats.earnedCents) }}
                </p>
            </div>
            <div class="rounded-2xl border bg-card p-4 shadow-xs">
                <p class="text-xs text-muted-foreground">Canceladas com ele</p>
                <p class="text-xl font-semibold">
                    {{ stats.cancelledAfterAccept }}
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <section class="flex flex-col gap-2">
                <h3 class="text-sm font-medium">Últimas corridas</h3>
                <p
                    v-if="runs.length === 0"
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Nenhuma corrida ainda.
                </p>
                <ul
                    v-else
                    class="flex flex-col divide-y rounded-xl border bg-card text-sm"
                >
                    <li
                        v-for="run in runs"
                        :key="run.id"
                        class="flex items-center gap-3 px-4 py-2"
                    >
                        <span class="flex-1">{{ run.storeName }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            when(run.at)
                        }}</span>
                        <Badge variant="secondary">{{ run.statusLabel }}</Badge>
                        <span class="font-medium">{{
                            formatCents(run.courierCents)
                        }}</span>
                    </li>
                </ul>
            </section>
            <section class="flex flex-col gap-2">
                <h3 class="text-sm font-medium">Repasses</h3>
                <p
                    v-if="payouts.length === 0"
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Nenhum repasse ainda.
                </p>
                <ul
                    v-else
                    class="flex flex-col divide-y rounded-xl border bg-card text-sm"
                >
                    <li
                        v-for="payout in payouts"
                        :key="payout.id"
                        class="flex items-center gap-3 px-4 py-2"
                    >
                        <span class="flex-1">Semana {{ payout.period }}</span>
                        <Badge
                            :variant="
                                payout.status === 'paid'
                                    ? 'secondary'
                                    : 'outline'
                            "
                            >{{
                                payout.status === 'paid' ? 'Pago' : 'A pagar'
                            }}</Badge
                        >
                        <span class="font-medium">{{
                            formatCents(payout.amountCents)
                        }}</span>
                    </li>
                </ul>
            </section>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    form.status === 'rejected'
                        ? 'Recusar cadastro'
                        : 'Bloquear entregador'
                }}</DialogTitle>
                <DialogDescription
                    >O entregador verá este motivo no app.</DialogDescription
                >
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="reason">Motivo</Label>
                <Input id="reason" v-model="form.reason" maxlength="255" />
                <InputError :message="form.errors.reason" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="dialogOpen = false"
                    >Voltar</Button
                >
                <Button
                    :variant="
                        form.status === 'blocked' ? 'destructive' : 'default'
                    "
                    :disabled="form.processing"
                    @click="confirmReason"
                    >Confirmar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
