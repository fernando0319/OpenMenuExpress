<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InstallmentController from '@/actions/App/Http/Controllers/Finance/InstallmentController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { formatDate, paidWord } from '@/lib/finance';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';
import type { InstallmentRow, Option } from '@/types/finance';

/**
 * "Baixa" of one installment: when, how much (interest or discount allowed) and how it was paid.
 */
const props = defineProps<{
    installment: InstallmentRow | null;
    paymentMethods: Option[];
    today: string;
    cashOpen: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    paid_on: props.today,
    amount: '',
    payment_method: 'pix',
    notes: '',
    through_cash: false,
});

watch(
    () => [open.value, props.installment?.id],
    () => {
        if (open.value && props.installment) {
            form.clearErrors();
            form.paid_on = props.today;
            form.amount = centsToInput(props.installment.amountCents);
            form.payment_method = 'pix';
            form.notes = '';
            form.through_cash = false;
        }
    },
    { immediate: true },
);

watch(
    () => form.payment_method,
    (method) => {
        form.through_cash = method === 'cash' && props.cashOpen;
    },
);

const words = computed(() => paidWord(props.installment?.type ?? 'payable'));

// Errors about the installment itself (already settled, title canceled) come from the server.
const serviceError = computed(
    () => (form.errors as Record<string, string | undefined>).installment,
);

const difference = computed(() => {
    const typed = parseMoney(form.amount);

    if (typed === null || !props.installment) {
        return null;
    }

    const diff = typed - props.installment.amountCents;

    if (diff === 0) {
        return null;
    }

    return diff > 0
        ? `${formatCents(diff)} a mais (juros ou multa)`
        : `${formatCents(-diff)} a menos (desconto)`;
});

function submit(): void {
    if (!props.installment) {
        return;
    }

    form.post(InstallmentController.settle.url(props.installment.id), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >{{ words.verb }}
                    {{
                        installment && installment.count > 1
                            ? `parcela ${installment.number}/${installment.count}`
                            : 'conta'
                    }}</DialogTitle
                >
                <DialogDescription v-if="installment">
                    {{ installment.description }}
                    <template v-if="installment.party">
                        · {{ installment.party }}</template
                    >
                    · vence {{ formatDate(installment.dueOn) }} ·
                    {{ formatCents(installment.amountCents) }}
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="settle-date"
                            >Data do {{ words.noun }}</Label
                        >
                        <Input
                            id="settle-date"
                            v-model="form.paid_on"
                            type="date"
                            :max="today"
                        />
                        <InputError :message="form.errors.paid_on" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="settle-amount">Valor (R$)</Label>
                        <Input
                            id="settle-amount"
                            v-model="form.amount"
                            inputmode="decimal"
                        />
                        <InputError :message="form.errors.amount" />
                    </div>
                </div>
                <p
                    v-if="difference"
                    class="-mt-2 text-xs text-muted-foreground"
                >
                    {{ difference }}
                </p>

                <div class="grid gap-2">
                    <Label for="settle-method">Forma de pagamento</Label>
                    <NativeSelect
                        id="settle-method"
                        v-model="form.payment_method"
                    >
                        <option
                            v-for="method in paymentMethods"
                            :key="method.value"
                            :value="method.value"
                        >
                            {{ method.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.payment_method" />
                </div>

                <div v-if="form.payment_method === 'cash'" class="grid gap-1">
                    <Label class="flex items-center gap-2 font-normal">
                        <Checkbox
                            v-model="form.through_cash"
                            :disabled="!cashOpen"
                        />
                        {{
                            installment?.type === 'payable'
                                ? 'Tirar o dinheiro do caixa aberto (sangria)'
                                : 'Colocar o dinheiro no caixa aberto (suprimento)'
                        }}
                    </Label>
                    <p v-if="!cashOpen" class="text-xs text-muted-foreground">
                        O caixa está fechado; abra-o no PDV para lançar o valor
                        nele.
                    </p>
                    <InputError :message="form.errors.through_cash" />
                </div>

                <div class="grid gap-2">
                    <Label for="settle-notes">Observação (opcional)</Label>
                    <Input
                        id="settle-notes"
                        v-model="form.notes"
                        maxlength="255"
                        placeholder="Ex.: comprovante no e-mail"
                    />
                </div>

                <InputError :message="serviceError" />

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing"
                        >Confirmar {{ words.noun }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
