<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Printer,
    ShoppingCart,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CashController from '@/actions/App/Http/Controllers/Pos/CashController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import CashSessionReport from '@/components/pos/CashSessionReport.vue';
import TextareaField from '@/components/TextareaField.vue';
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
import { formatCents, parseMoney } from '@/lib/money';
import { index, show } from '@/routes/cash';
import { show as posShow } from '@/routes/pos';
import type { CashSessionDetail, CashSessionRow } from '@/types/cash';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Caixa', href: index() }],
    },
});

const props = defineProps<{
    session: CashSessionDetail | null;
    history: CashSessionRow[];
}>();

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '';
}

/** Errors about the session itself (already open, already closed). */
const page = usePage();
const cashError = computed(() => page.props.errors?.cash);

// Opening ------------------------------------------------------------------

const openForm = useForm({ opening: '' });

function openCash(): void {
    openForm.post(CashController.open.url(), { preserveScroll: true });
}

// Supply / withdrawal ------------------------------------------------------

const moveOpen = ref(false);
const moveForm = useForm({
    type: 'supply' as 'supply' | 'withdrawal',
    amount: '',
    description: '',
});

function startMove(type: 'supply' | 'withdrawal'): void {
    moveForm.reset();
    moveForm.clearErrors();
    moveForm.type = type;
    moveOpen.value = true;
}

function saveMove(): void {
    moveForm.post(CashController.move.url(), {
        preserveScroll: true,
        onSuccess: () => {
            moveOpen.value = false;
        },
    });
}

// Closing ------------------------------------------------------------------

const closeOpen = ref(false);
const closeForm = useForm({ counted: '', notes: '', print: true });

const closeDifference = computed(() => {
    const counted = parseMoney(closeForm.counted);

    if (counted === null || !props.session) {
        return null;
    }

    return counted - props.session.summary.expectedCashCents;
});

function closeCash(): void {
    closeForm.post(CashController.close.url(), {
        onSuccess: () => {
            closeOpen.value = false;
        },
    });
}

function printPartial(): void {
    if (props.session) {
        router.post(
            CashController.print.url(props.session.id),
            {},
            { preserveScroll: true },
        );
    }
}
</script>

<template>
    <Head title="Caixa" />

    <div class="flex max-w-5xl flex-col gap-6 p-4">
        <template v-if="!session">
            <Heading
                title="Caixa fechado"
                description="Abra o caixa para começar a vender no PDV e registrar os pagamentos."
            />
            <form
                class="flex max-w-sm flex-col gap-3 rounded-xl border p-4"
                @submit.prevent="openCash"
            >
                <div class="grid gap-2">
                    <Label for="opening"
                        >Fundo de troco (dinheiro na gaveta)</Label
                    >
                    <Input
                        id="opening"
                        v-model="openForm.opening"
                        v-focus
                        inputmode="decimal"
                        placeholder="0,00"
                    />
                    <InputError
                        :message="openForm.errors.opening ?? cashError"
                    />
                </div>
                <Button type="submit" :disabled="openForm.processing">
                    Abrir caixa
                </Button>
            </form>
        </template>

        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <Heading
                    title="Caixa aberto"
                    :description="`Desde ${formatDate(session.openedAt)}${session.openedBy ? ` · ${session.openedBy}` : ''}`"
                />
                <div class="ml-auto flex flex-wrap gap-2">
                    <Button as-child>
                        <Link :href="posShow()">
                            <ShoppingCart />
                            Ir para o PDV
                        </Link>
                    </Button>
                    <Button variant="outline" @click="startMove('supply')">
                        <ArrowDownToLine />
                        Suprimento
                    </Button>
                    <Button variant="outline" @click="startMove('withdrawal')">
                        <ArrowUpFromLine />
                        Sangria
                    </Button>
                    <Button variant="outline" @click="printPartial">
                        <Printer />
                        Parcial
                    </Button>
                    <Button
                        variant="destructive"
                        @click="
                            closeForm.reset();
                            closeForm.clearErrors();
                            closeOpen = true;
                        "
                    >
                        Fechar caixa
                    </Button>
                </div>
            </div>

            <CashSessionReport :session="session" />
        </template>

        <section v-if="history.length > 0" class="flex flex-col gap-2">
            <h2 class="font-semibold">Caixas anteriores</h2>
            <div class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-left">
                        <tr>
                            <th class="p-3 font-medium">Fechamento</th>
                            <th class="p-3 font-medium">Por</th>
                            <th class="p-3 text-right font-medium">Esperado</th>
                            <th class="p-3 text-right font-medium">Contado</th>
                            <th class="p-3 text-right font-medium">
                                Diferença
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="row in history"
                            :key="row.id"
                            class="cursor-pointer hover:bg-muted/40"
                            @click="router.visit(show(row.id))"
                        >
                            <td class="p-3">
                                <Link
                                    :href="show(row.id)"
                                    class="underline-offset-4 hover:underline"
                                    >{{ formatDate(row.closedAt) }}</Link
                                >
                            </td>
                            <td class="p-3">{{ row.closedBy ?? '—' }}</td>
                            <td class="p-3 text-right">
                                {{ formatCents(row.expectedCashCents ?? 0) }}
                            </td>
                            <td class="p-3 text-right">
                                {{ formatCents(row.countedCashCents ?? 0) }}
                            </td>
                            <td
                                class="p-3 text-right font-medium"
                                :class="
                                    (row.differenceCents ?? 0) < 0
                                        ? 'text-destructive'
                                        : (row.differenceCents ?? 0) > 0
                                          ? 'text-amber-600'
                                          : 'text-emerald-600'
                                "
                            >
                                {{ formatCents(row.differenceCents ?? 0) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <Dialog v-model:open="moveOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>{{
                    moveForm.type === 'supply' ? 'Suprimento' : 'Sangria'
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        moveForm.type === 'supply'
                            ? 'Dinheiro colocado na gaveta (ex.: reforço de troco).'
                            : 'Dinheiro retirado da gaveta (ex.: depósito, pagamento de fornecedor).'
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="saveMove">
                <div class="grid gap-2">
                    <Label for="move-amount">Valor</Label>
                    <Input
                        id="move-amount"
                        v-model="moveForm.amount"
                        inputmode="decimal"
                        placeholder="0,00"
                    />
                    <InputError
                        :message="moveForm.errors.amount ?? cashError"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="move-description">Motivo (opcional)</Label>
                    <Input
                        id="move-description"
                        v-model="moveForm.description"
                        maxlength="120"
                    />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="moveForm.processing">
                        Registrar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="closeOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Fechar caixa</DialogTitle>
                <DialogDescription>
                    Conte o dinheiro da gaveta e informe o valor. O sistema
                    mostra se sobrou ou faltou.
                </DialogDescription>
            </DialogHeader>
            <form v-if="session" class="grid gap-3" @submit.prevent="closeCash">
                <p class="text-sm">
                    Esperado:
                    <strong>{{
                        formatCents(session.summary.expectedCashCents)
                    }}</strong>
                </p>
                <div class="grid gap-2">
                    <Label for="counted">Dinheiro contado</Label>
                    <Input
                        id="counted"
                        v-model="closeForm.counted"
                        inputmode="decimal"
                        placeholder="0,00"
                    />
                    <p
                        v-if="closeDifference !== null"
                        class="text-sm font-medium"
                        :class="
                            closeDifference < 0
                                ? 'text-destructive'
                                : closeDifference > 0
                                  ? 'text-amber-600'
                                  : 'text-emerald-600'
                        "
                    >
                        <template v-if="closeDifference === 0"
                            >Confere, sem diferença.</template
                        >
                        <template v-else-if="closeDifference > 0"
                            >Sobra de
                            {{ formatCents(closeDifference) }}</template
                        >
                        <template v-else
                            >Falta de
                            {{ formatCents(-closeDifference) }}</template
                        >
                    </p>
                    <InputError
                        :message="closeForm.errors.counted ?? cashError"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="close-notes">Observações (opcional)</Label>
                    <TextareaField
                        id="close-notes"
                        v-model="closeForm.notes"
                        maxlength="500"
                        class="min-h-16"
                    />
                </div>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox v-model="closeForm.print" />
                    Imprimir o relatório de fechamento
                </Label>
                <DialogFooter>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="
                            closeForm.processing || closeDifference === null
                        "
                    >
                        Fechar caixa
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
