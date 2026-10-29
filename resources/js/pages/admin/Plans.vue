<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import BillingAdminController from '@/actions/App/Http/Controllers/Admin/BillingAdminController';
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
import { centsToInput, formatCents } from '@/lib/money';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/plans';

type PlanRow = {
    id: number;
    name: string;
    priceCents: number;
    interval: 'month' | 'year';
    freeUpToCents: number | null;
    percent: string;
    percentBase: 'excess' | 'total';
    feeMinCents: number | null;
    feeMaxCents: number | null;
    usageSummary: string | null;
    modules: string[];
    isActive: boolean;
    storesCount: number;
};

const props = defineProps<{
    plans: PlanRow[];
    modules: { value: string; label: string }[];
    billingEnabled: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Planos', href: index() },
        ],
    },
});

const chargeOptions = [
    { value: 'fixed', label: 'Valor fixo' },
    { value: 'usage', label: '% das vendas online' },
] as const;

const open = ref(false);
const editing = ref<PlanRow | null>(null);
const form = useForm({
    name: '',
    price: '',
    interval: 'month',
    free_up_to: '',
    charge: 'fixed' as 'fixed' | 'usage',
    percent: '',
    percent_base: 'excess' as 'excess' | 'total',
    fee_min: '',
    fee_max: '',
    modules: [] as string[],
    is_active: true,
});

function start(plan: PlanRow | null): void {
    editing.value = plan;
    form.clearErrors();
    form.name = plan?.name ?? '';
    form.price = plan ? centsToInput(plan.priceCents) : '';
    form.interval = plan?.interval ?? 'month';
    form.free_up_to =
        plan?.freeUpToCents != null ? centsToInput(plan.freeUpToCents) : '';
    form.charge = plan?.percent ? 'usage' : 'fixed';
    form.percent = plan?.percent ?? '';
    form.percent_base = plan?.percentBase ?? 'excess';
    form.fee_min =
        plan?.feeMinCents != null ? centsToInput(plan.feeMinCents) : '';
    form.fee_max =
        plan?.feeMaxCents != null ? centsToInput(plan.feeMaxCents) : '';
    form.modules = plan
        ? [...plan.modules]
        : props.modules.map((module) => module.value);
    form.is_active = plan?.isActive ?? true;
    open.value = true;
}

function toggleModule(value: string, checked: boolean | 'indeterminate'): void {
    form.modules =
        checked === true
            ? [...new Set([...form.modules, value])]
            : form.modules.filter((item) => item !== value);
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (open.value = false),
    };

    // A fixed-price plan carries no percentage terms.
    form.transform((data) =>
        data.charge === 'usage'
            ? data
            : { ...data, percent: '', fee_min: '', fee_max: '' },
    );

    if (editing.value) {
        form.put(
            BillingAdminController.updatePlan.url(editing.value.id),
            options,
        );
    } else {
        form.post(BillingAdminController.storePlan.url(), options);
    }
}

function moduleLabel(value: string): string {
    return (
        props.modules.find((module) => module.value === value)?.label ?? value
    );
}
</script>

<template>
    <Head title="Planos" />

    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                title="Planos"
                description="O que cada plano inclui. Cardápio, pedidos e catálogo estão sempre incluídos."
            />
            <Button class="ml-auto" @click="start(null)"
                ><Plus /> Novo plano</Button
            >
        </div>

        <p
            v-if="!billingEnabled"
            class="rounded-xl border border-amber-400/50 bg-amber-500/10 p-4 text-sm"
        >
            A cobrança está desligada nesta instalação
            (<code>BILLING_ENABLED=false</code>): nenhuma loja é bloqueada e os
            planos não limitam nada. Ligue no <code>.env</code> do servidor
            hospedado.
        </p>

        <p
            v-if="plans.length === 0"
            class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
        >
            Nenhum plano ainda. Crie o primeiro para gerar vouchers.
        </p>

        <div v-else class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <div
                v-for="plan in plans"
                :key="plan.id"
                class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
                :class="{ 'opacity-60': !plan.isActive }"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h2 class="font-semibold">{{ plan.name }}</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ plan.storesCount }} loja(s)
                        </p>
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Editar ${plan.name}`"
                        @click="start(plan)"
                        ><Pencil
                    /></Button>
                </div>
                <p v-if="plan.usageSummary" class="text-sm font-medium">
                    {{ plan.usageSummary }}
                </p>
                <p v-else>
                    <span class="text-2xl font-semibold">{{
                        formatCents(plan.priceCents)
                    }}</span>
                    <span class="ml-1 text-sm text-muted-foreground">{{
                        plan.interval === 'year' ? 'por ano' : 'por mês'
                    }}</span>
                </p>
                <p
                    v-if="plan.freeUpToCents !== null && !plan.usageSummary"
                    class="-mt-2 text-sm text-muted-foreground"
                >
                    Grátis até {{ formatCents(plan.freeUpToCents) }} em vendas
                    por mês
                </p>
                <div class="flex flex-wrap gap-1">
                    <Badge
                        v-for="module in plan.modules"
                        :key="module"
                        variant="secondary"
                        >{{ moduleLabel(module) }}</Badge
                    >
                    <span
                        v-if="plan.modules.length === 0"
                        class="text-xs text-muted-foreground"
                        >Só o essencial</span
                    >
                </div>
                <Badge
                    v-if="!plan.isActive"
                    variant="outline"
                    class="self-start"
                    >Inativo</Badge
                >
            </div>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92dvh] overflow-y-auto sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Editar plano' : 'Novo plano'
                }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="plan-name">Nome</Label>
                    <Input
                        id="plan-name"
                        v-model="form.name"
                        maxlength="60"
                        placeholder="Ex.: Completo"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div
                    class="flex gap-1 rounded-xl bg-muted p-1"
                    role="radiogroup"
                    aria-label="Forma de cobrança"
                >
                    <button
                        v-for="option in chargeOptions"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.charge === option.value"
                        class="flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            form.charge === option.value
                                ? 'bg-background shadow-xs'
                                : 'text-muted-foreground'
                        "
                        @click="form.charge = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <div
                    v-if="form.charge === 'usage'"
                    class="grid gap-3 rounded-xl border p-3"
                >
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="plan-percent">Percentual (%)</Label>
                            <Input
                                id="plan-percent"
                                v-model="form.percent"
                                inputmode="decimal"
                                placeholder="Ex.: 2 ou 1,5"
                            />
                            <InputError :message="form.errors.percent" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan-base"
                                >Acima do limite, cobrar</Label
                            >
                            <NativeSelect
                                id="plan-base"
                                v-model="form.percent_base"
                            >
                                <option value="excess">
                                    Só sobre o que passar
                                </option>
                                <option value="total">
                                    Sobre o total do mês
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan-min">Mínimo (R$, opcional)</Label>
                            <Input
                                id="plan-min"
                                v-model="form.fee_min"
                                inputmode="decimal"
                                placeholder="Ex.: 19,00"
                            />
                            <InputError :message="form.errors.fee_min" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="plan-max">Teto (R$, opcional)</Label>
                            <Input
                                id="plan-max"
                                v-model="form.fee_max"
                                inputmode="decimal"
                                placeholder="Ex.: 299,00"
                            />
                            <InputError :message="form.errors.fee_max" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Base: valor dos produtos dos pedidos concluídos do
                        cardápio online no mês (sem taxa de entrega; PDV e mesas
                        não entram). A fatura fecha no fim do mês e vence no dia
                        1º, com a mesma carência. Mínimo e teto valem quando há
                        cobrança.
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="plan-price">{{
                            form.charge === 'usage'
                                ? 'Valor fixo mensal (R$)'
                                : 'Preço (R$)'
                        }}</Label>
                        <Input
                            id="plan-price"
                            v-model="form.price"
                            inputmode="decimal"
                            placeholder="99,90"
                        />
                        <InputError :message="form.errors.price" />
                    </div>
                    <div v-if="form.charge === 'fixed'" class="grid gap-2">
                        <Label for="plan-interval">Período</Label>
                        <NativeSelect
                            id="plan-interval"
                            v-model="form.interval"
                        >
                            <option value="month">Mensal</option>
                            <option value="year">Anual</option>
                        </NativeSelect>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="plan-free">{{
                        form.charge === 'usage'
                            ? 'Grátis até (R$ em vendas online no mês)'
                            : 'Gratuito até (R$ vendidos no mês)'
                    }}</Label>
                    <Input
                        id="plan-free"
                        v-model="form.free_up_to"
                        inputmode="decimal"
                        placeholder="Ex.: 3000,00 (vazio = sempre cobrado)"
                    />
                    <p
                        v-if="form.charge === 'usage'"
                        class="text-xs text-muted-foreground"
                    >
                        Abaixo desse valor o mês sai grátis (nem o valor fixo é
                        cobrado). Vazio = cobra desde a primeira venda.
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        Lojas que venderem até esse valor no mês não pagam; ao
                        passar, pagam a partir do mês seguinte. Lojas sem plano
                        pago caem no primeiro plano gratuito ativo.
                    </p>
                    <InputError :message="form.errors.free_up_to" />
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Módulos incluídos
                    </legend>
                    <Label
                        v-for="module in modules"
                        :key="module.value"
                        class="flex items-center gap-2 font-normal"
                    >
                        <Checkbox
                            :model-value="form.modules.includes(module.value)"
                            @update:model-value="
                                toggleModule(module.value, $event)
                            "
                        />
                        {{ module.label }}
                    </Label>
                </fieldset>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox v-model="form.is_active" />
                    Disponível para novos vouchers
                </Label>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
