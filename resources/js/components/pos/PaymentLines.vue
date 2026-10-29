<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';
import { tallyPayments } from '@/lib/payments';
import type { PaymentLine } from '@/lib/payments';
import type { PaymentMethodOption } from '@/types/menu';

const props = defineProps<{
    totalCents: number;
    methods: PaymentMethodOption[];
}>();

const lines = defineModel<PaymentLine[]>({ required: true });

const tally = computed(() => tallyPayments(lines.value, props.totalCents));

/** Common notes the customer hands over, above the total. */
const cashSuggestions = computed(() => {
    const total = props.totalCents;
    const options = [500, 1000, 2000, 5000, 10000, 20000]
        .map((note) => Math.ceil(total / note) * note)
        .filter((value) => value > total);

    return [...new Set(options)].slice(0, 3);
});

function add(): void {
    const used = new Set(lines.value.map((line) => line.method));
    const next =
        props.methods.find((method) => !used.has(method.value)) ??
        props.methods[0];

    lines.value.push({
        method: next.value,
        amount:
            tally.value.remainingCents > 0
                ? centsToInput(tally.value.remainingCents)
                : '',
    });
}

function remove(index: number): void {
    lines.value.splice(index, 1);
}

/** Fills this line with what is still missing (or the exact total). */
function fillRemaining(index: number): void {
    const others = lines.value.reduce(
        (sum, line, position) =>
            position === index ? sum : sum + (parseMoney(line.amount) ?? 0),
        0,
    );

    lines.value[index].amount = centsToInput(
        Math.max(0, props.totalCents - others),
    );
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            v-for="(line, index) in lines"
            :key="index"
            class="flex flex-col gap-2 rounded-lg border p-3"
        >
            <div class="flex items-center gap-2">
                <NativeSelect
                    v-model="line.method"
                    class="flex-1"
                    :aria-label="`Forma de pagamento ${index + 1}`"
                >
                    <option
                        v-for="method in methods"
                        :key="method.value"
                        :value="method.value"
                    >
                        {{ method.label }}
                    </option>
                </NativeSelect>
                <Input
                    v-model="line.amount"
                    inputmode="decimal"
                    placeholder="0,00"
                    class="w-28 text-right"
                    :aria-label="`Valor ${index + 1}`"
                />
                <Button
                    v-if="lines.length > 1"
                    type="button"
                    variant="ghost"
                    size="icon"
                    :aria-label="`Remover forma ${index + 1}`"
                    @click="remove(index)"
                >
                    <X />
                </Button>
            </div>
            <div class="flex flex-wrap gap-1.5">
                <Button
                    type="button"
                    variant="secondary"
                    size="sm"
                    @click="fillRemaining(index)"
                >
                    Valor restante
                </Button>
                <template v-if="line.method === 'cash'">
                    <Button
                        v-for="value in cashSuggestions"
                        :key="value"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="line.amount = centsToInput(value)"
                    >
                        {{ formatCents(value) }}
                    </Button>
                </template>
            </div>
        </div>

        <Button
            v-if="lines.length < 5"
            type="button"
            variant="outline"
            size="sm"
            class="self-start"
            @click="add"
        >
            <Plus />
            Dividir pagamento
        </Button>

        <dl class="grid gap-1 rounded-lg bg-muted/50 p-3 text-sm">
            <div class="flex justify-between">
                <dt>Total</dt>
                <dd>{{ formatCents(totalCents) }}</dd>
            </div>
            <div class="flex justify-between">
                <dt>Recebido</dt>
                <dd>{{ formatCents(tally.receivedCents) }}</dd>
            </div>
            <div
                v-if="tally.remainingCents > 0"
                class="flex justify-between font-semibold text-destructive"
            >
                <dt>Falta</dt>
                <dd>{{ formatCents(tally.remainingCents) }}</dd>
            </div>
            <div
                v-else-if="tally.changeCents > 0"
                class="flex justify-between text-base font-semibold text-emerald-600"
            >
                <dt>Troco</dt>
                <dd>{{ formatCents(tally.changeCents) }}</dd>
            </div>
        </dl>
        <p v-if="tally.changeWithoutCash" class="text-sm text-destructive">
            Só o pagamento em dinheiro pode ter troco.
        </p>
    </div>
</template>
