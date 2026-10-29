<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Lock, Plus, RefreshCw } from '@lucide/vue';
import { computed, ref, watch, watchEffect } from 'vue';
import SupplierController from '@/actions/App/Http/Controllers/Finance/SupplierController';
import TitleController from '@/actions/App/Http/Controllers/Finance/TitleController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import TextareaField from '@/components/TextareaField.vue';
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
import { dueDate, splitCents } from '@/lib/finance';
import type { Frequency, PlannedInstallment } from '@/lib/finance';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';
import { overview, payables, receivables } from '@/routes/finance';
import { index as categoriesIndex } from '@/routes/finance/categories';
import { show } from '@/routes/finance/titles';
import type { FinanceKind, NamedOption, Option } from '@/types/finance';

type TitleData = {
    id: number;
    description: string;
    supplierId: number | null;
    counterparty: string | null;
    categoryId: number | null;
    documentNumber: string | null;
    issuedOn: string;
    totalCents: number;
    frequency: Frequency;
    autoSettle: boolean;
    autoSettleMethod: string | null;
    notes: string | null;
};

const props = defineProps<{
    type: FinanceKind;
    title: TitleData | null;
    installments: PlannedInstallment[];
    locked: boolean;
    preselectedSupplierId: number | null;
    today: string;
    suppliers: NamedOption[];
    categories: NamedOption[];
    frequencies: Option[];
    paymentMethods: Option[];
}>();

const isPayable = computed(() => props.type === 'payable');
const listLabel = computed(() =>
    isPayable.value ? 'Contas a pagar' : 'Contas a receber',
);
const heading = computed(() => {
    if (props.title) {
        return 'Editar lançamento';
    }

    return isPayable.value ? 'Nova conta a pagar' : 'Nova conta a receber';
});

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Financeiro', href: overview() },
            {
                title: listLabel.value,
                href: isPayable.value ? payables() : receivables(),
            },
            { title: heading.value, href: '#' },
        ],
    });
});

const form = useForm({
    type: props.type,
    description: props.title?.description ?? '',
    supplier_id: (props.title?.supplierId ??
        props.preselectedSupplierId ??
        '') as number | string,
    counterparty: props.title?.counterparty ?? '',
    category_id: (props.title?.categoryId ?? '') as number | string,
    document_number: props.title?.documentNumber ?? '',
    issued_on: props.title?.issuedOn ?? props.today,
    total: props.title ? centsToInput(props.title.totalCents) : '',
    frequency: (props.title?.frequency ?? 'monthly') as Frequency,
    installments: props.installments.length
        ? props.installments.map((row) => ({ ...row }))
        : [{ due_on: props.today, amount: '' }],
    auto_settle: props.title?.autoSettle ?? false,
    auto_settle_method: props.title?.autoSettleMethod ?? 'auto_debit',
    notes: props.title?.notes ?? '',
    another: false,
});

// The split parameters: the rows below are proposed from them and can be adjusted one by one.
const count = ref(Math.max(1, props.installments.length));
const firstDue = ref(props.installments[0]?.due_on ?? props.today);
const inInstallments = ref(count.value > 1);

function regenerate(): void {
    const total = parseMoney(form.total) ?? 0;
    const quantity = inInstallments.value ? count.value : 1;

    form.installments = splitCents(total, quantity).map((cents, index) => ({
        due_on: dueDate(firstDue.value, index, form.frequency),
        amount: total > 0 ? centsToInput(cents) : '',
    }));
}

watch(
    () => [
        form.total,
        count.value,
        firstDue.value,
        form.frequency,
        inInstallments.value,
    ],
    () => {
        if (!props.locked && firstDue.value) {
            regenerate();
        }
    },
);

const sumCents = computed(() =>
    form.installments.reduce(
        (sum, row) => sum + (parseMoney(row.amount) ?? 0),
        0,
    ),
);
const totalCents = computed(() => parseMoney(form.total));
const mismatch = computed(
    () => totalCents.value !== null && sumCents.value !== totalCents.value,
);

const installmentErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key.startsWith('installments'))
        .map(([, message]) => message),
);

function submit(another = false): void {
    form.another = another;

    if (props.title) {
        form.put(TitleController.update.url(props.title.id));

        return;
    }

    form.post(TitleController.store.url(), {
        onSuccess: () => {
            if (another) {
                form.reset();
                count.value = 1;
                firstDue.value = props.today;
                inInstallments.value = false;
            }
        },
    });
}

// Quick supplier registration without leaving the form.
const supplierOpen = ref(false);
const supplierForm = useForm({ name: '', document: '', phone: '' });

function saveSupplier(): void {
    const known = new Set(props.suppliers.map((supplier) => supplier.id));

    supplierForm.post(SupplierController.store.url(), {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            supplierOpen.value = false;
            supplierForm.reset();
            const added = props.suppliers.find(
                (supplier) => !known.has(supplier.id),
            );

            if (added) {
                form.supplier_id = added.id;
            }
        },
    });
}
</script>

<template>
    <Head :title="heading" />

    <div class="flex max-w-4xl flex-col gap-6 p-4 md:p-6">
        <Heading
            :title="heading"
            :description="
                isPayable
                    ? 'Uma compra, um boleto ou uma despesa. Parcele semanal, quinzenal ou mensalmente.'
                    : 'Um valor a receber: venda a prazo, convênio, encomenda. Pode ser parcelado.'
            "
        />

        <form class="flex flex-col gap-6" @submit.prevent="submit(false)">
            <section
                class="grid gap-4 rounded-2xl border bg-card p-5 shadow-xs sm:grid-cols-2"
            >
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="description">Descrição</Label>
                    <Input
                        id="description"
                        v-model="form.description"
                        maxlength="160"
                        :placeholder="
                            isPayable
                                ? 'Ex.: Compra de carnes, Conta de luz de outubro'
                                : 'Ex.: Coffee break da empresa X'
                        "
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <div v-if="isPayable" class="grid gap-2">
                    <Label for="supplier">Fornecedor</Label>
                    <div class="flex gap-2">
                        <NativeSelect
                            id="supplier"
                            v-model="form.supplier_id"
                            class="min-w-0 flex-1"
                        >
                            <option value="">Sem fornecedor cadastrado</option>
                            <option
                                v-for="supplier in suppliers"
                                :key="supplier.id"
                                :value="supplier.id"
                            >
                                {{ supplier.name }}
                            </option>
                        </NativeSelect>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Cadastrar fornecedor"
                            @click="supplierOpen = true"
                            ><Plus
                        /></Button>
                    </div>
                    <InputError :message="form.errors.supplier_id" />
                </div>

                <div
                    v-if="!isPayable || form.supplier_id === ''"
                    class="grid gap-2"
                >
                    <Label for="counterparty">{{
                        isPayable
                            ? 'Favorecido (opcional)'
                            : 'Cliente ou pagador'
                    }}</Label>
                    <Input
                        id="counterparty"
                        v-model="form.counterparty"
                        maxlength="120"
                        :placeholder="
                            isPayable
                                ? 'Ex.: Copel, Prefeitura'
                                : 'Ex.: Empresa X'
                        "
                    />
                    <InputError :message="form.errors.counterparty" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="category">Categoria</Label>
                        <Link
                            :href="categoriesIndex()"
                            class="text-xs text-primary hover:underline"
                            >Gerenciar</Link
                        >
                    </div>
                    <NativeSelect id="category" v-model="form.category_id">
                        <option value="">Sem categoria</option>
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.category_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="document">Nº do documento (opcional)</Label>
                    <Input
                        id="document"
                        v-model="form.document_number"
                        maxlength="40"
                        placeholder="Nota fiscal, boleto, pedido"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="issued">{{
                        isPayable ? 'Data da compra' : 'Data da venda'
                    }}</Label>
                    <Input id="issued" v-model="form.issued_on" type="date" />
                    <InputError :message="form.errors.issued_on" />
                </div>
            </section>

            <section
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Valor e vencimentos</h2>
                    <span
                        v-if="locked"
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Lock class="size-3.5" /> Já há parcelas baixadas:
                        valores e datas não mudam mais.
                    </span>
                </div>

                <fieldset :disabled="locked" class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="total">Valor total (R$)</Label>
                        <Input
                            id="total"
                            v-model="form.total"
                            inputmode="decimal"
                            placeholder="1500,00"
                        />
                        <InputError :message="form.errors.total" />
                    </div>

                    <div
                        class="flex gap-1 self-end rounded-xl bg-muted p-1"
                        role="radiogroup"
                        aria-label="Condição"
                    >
                        <button
                            v-for="option in [
                                { value: false, label: 'À vista' },
                                { value: true, label: 'Parcelado' },
                            ]"
                            :key="option.label"
                            type="button"
                            role="radio"
                            :aria-checked="inInstallments === option.value"
                            class="flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                            :class="
                                inInstallments === option.value
                                    ? 'bg-background shadow-xs'
                                    : 'text-muted-foreground'
                            "
                            @click="inInstallments = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <template v-if="inInstallments">
                        <div class="grid gap-2">
                            <Label for="count">Nº de parcelas</Label>
                            <Input
                                id="count"
                                v-model.number="count"
                                type="number"
                                min="2"
                                max="120"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="frequency">Frequência</Label>
                            <NativeSelect
                                id="frequency"
                                v-model="form.frequency"
                            >
                                <option
                                    v-for="frequency in frequencies"
                                    :key="frequency.value"
                                    :value="frequency.value"
                                >
                                    {{ frequency.label }}
                                </option>
                            </NativeSelect>
                        </div>
                    </template>

                    <div class="grid gap-2">
                        <Label for="first-due">{{
                            inInstallments ? '1º vencimento' : 'Vencimento'
                        }}</Label>
                        <Input id="first-due" v-model="firstDue" type="date" />
                    </div>
                </fieldset>

                <div
                    v-if="form.installments.length > 1 || locked"
                    class="flex flex-col gap-2"
                >
                    <div class="flex items-center justify-between gap-2">
                        <h3 class="text-sm font-medium">Parcelas</h3>
                        <Button
                            v-if="!locked"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="regenerate"
                            ><RefreshCw /> Recalcular</Button
                        >
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Ajuste a data ou o valor de qualquer parcela, se
                        precisar. A soma tem de bater com o total.
                    </p>
                    <div class="overflow-x-auto rounded-xl border">
                        <table class="w-full text-sm">
                            <thead
                                class="bg-muted/50 text-left text-muted-foreground"
                            >
                                <tr>
                                    <th class="w-12 px-3 py-2 font-medium">
                                        #
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Vencimento
                                    </th>
                                    <th class="px-3 py-2 font-medium">
                                        Valor (R$)
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(row, index) in form.installments"
                                    :key="index"
                                    class="border-t"
                                >
                                    <td
                                        class="px-3 py-1.5 text-muted-foreground"
                                    >
                                        {{ index + 1 }}
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <Input
                                            v-model="row.due_on"
                                            type="date"
                                            class="h-8"
                                            :disabled="locked"
                                            :aria-label="`Vencimento da parcela ${index + 1}`"
                                        />
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <Input
                                            v-model="row.amount"
                                            inputmode="decimal"
                                            class="h-8"
                                            :disabled="locked"
                                            :aria-label="`Valor da parcela ${index + 1}`"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        class="text-sm"
                        :class="
                            mismatch
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        Soma das parcelas: {{ formatCents(sumCents) }}
                        <template v-if="mismatch && totalCents !== null">
                            (diferença de
                            {{ formatCents(Math.abs(totalCents - sumCents)) }})
                        </template>
                    </p>
                </div>
                <InputError
                    v-for="message in installmentErrors"
                    :key="message"
                    :message="message"
                />

                <div class="grid gap-2 border-t pt-4">
                    <Label class="flex items-start gap-2 font-normal">
                        <Checkbox v-model="form.auto_settle" class="mt-0.5" />
                        <span>
                            Dar baixa automaticamente no vencimento
                            <span class="block text-xs text-muted-foreground">
                                Para débito automático ou cartão cadastrado: a
                                parcela é marcada como
                                {{ isPayable ? 'paga' : 'recebida' }} sozinha no
                                dia.
                            </span>
                        </span>
                    </Label>
                    <div
                        v-if="form.auto_settle"
                        class="grid max-w-xs gap-2 pl-6"
                    >
                        <Label for="auto-method">Forma de pagamento</Label>
                        <NativeSelect
                            id="auto-method"
                            v-model="form.auto_settle_method"
                        >
                            <option
                                v-for="method in paymentMethods"
                                :key="method.value"
                                :value="method.value"
                            >
                                {{ method.label }}
                            </option>
                        </NativeSelect>
                        <InputError :message="form.errors.auto_settle_method" />
                    </div>
                </div>
            </section>

            <section
                class="grid gap-2 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <Label for="notes">Observações (opcional)</Label>
                <TextareaField
                    id="notes"
                    v-model="form.notes"
                    maxlength="2000"
                />
            </section>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :disabled="form.processing"
                    >Salvar</Button
                >
                <Button
                    v-if="!title"
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="submit(true)"
                    >Salvar e lançar outra</Button
                >
                <Button variant="ghost" as-child>
                    <Link
                        :href="
                            title
                                ? show(title.id)
                                : isPayable
                                  ? payables()
                                  : receivables()
                        "
                        >Cancelar</Link
                    >
                </Button>
            </div>
        </form>
    </div>

    <Dialog v-model:open="supplierOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Novo fornecedor</DialogTitle>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveSupplier">
                <div class="grid gap-2">
                    <Label for="quick-name">Nome</Label>
                    <Input
                        id="quick-name"
                        v-model="supplierForm.name"
                        maxlength="120"
                    />
                    <InputError :message="supplierForm.errors.name" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="quick-document">CPF ou CNPJ</Label>
                        <Input
                            id="quick-document"
                            v-model="supplierForm.document"
                            inputmode="numeric"
                        />
                        <InputError :message="supplierForm.errors.document" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="quick-phone">Telefone</Label>
                        <Input
                            id="quick-phone"
                            v-model="supplierForm.phone"
                            inputmode="tel"
                        />
                        <InputError :message="supplierForm.errors.phone" />
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Os outros dados podem ser completados depois em
                    Fornecedores.
                </p>
                <DialogFooter>
                    <Button type="submit" :disabled="supplierForm.processing"
                        >Cadastrar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
