<script setup lang="ts">
import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Merge,
    Minus,
    Plus,
    ReceiptText,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import TableController from '@/actions/App/Http/Controllers/Tables/TableController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PaymentLines from '@/components/pos/PaymentLines.vue';
import ProductPicker from '@/components/pos/ProductPicker.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useCart } from '@/composables/useCart';
import { cartItemDetails, cartItemPayload } from '@/lib/cart';
import { centsToInput, formatCents, parseMoney } from '@/lib/money';
import { paymentsReady, tallyPayments } from '@/lib/payments';
import type { PaymentLine } from '@/lib/payments';
import { index as cashIndex } from '@/routes/cash';
import { edit as orderEdit } from '@/routes/orders';
import { index } from '@/routes/tables';
import type { MenuCategory, PaymentMethodOption } from '@/types/menu';
import type { TabDetail } from '@/types/tables';

const props = defineProps<{
    table: { id: number; name: string; seats: number | null };
    tab: TabDetail | null;
    categories: MenuCategory[];
    paymentMethods: PaymentMethodOption[];
    freeTables: { id: number; name: string }[];
    joinableTables: { id: number; name: string; occupied: boolean }[];
    canClose: boolean;
    cashOpen: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mesas', href: index() }],
    },
});

usePoll(10_000, { only: ['tab', 'cashOpen'] });

const time = new Intl.DateTimeFormat('pt-BR', { timeStyle: 'short' });

function formatTime(value: string): string {
    return time.format(new Date(value));
}

const errors = ref<Record<string, string>>({});
const firstError = computed(() => Object.values(errors.value)[0] ?? null);

function post(
    url: string,
    data: RequestPayload = {},
    onSuccess?: () => void,
): void {
    errors.value = {};
    router.post(url, data, {
        preserveScroll: true,
        onSuccess,
        onError: (bag) => {
            errors.value = bag;
        },
    });
}

// Opening ------------------------------------------------------------------

const openForm = useForm({ people: '', customer_name: '' });
const page = usePage();
/** Set when someone else opened the table a moment before. */
const tableError = computed(() => page.props.errors?.table);

function openTab(): void {
    openForm.post(TableController.open.url(props.table.id), {
        preserveScroll: true,
    });
}

// New round ----------------------------------------------------------------

/** Mobile shows one panel at a time; desktop shows both side by side. */
const panel = ref<'bill' | 'add'>('bill');
const cart = useCart(`table:${props.table.id}`);
const roundNotes = ref('');
const reviewOpen = ref(false);
const sending = ref(false);

function sendRound(): void {
    if (!props.tab) {
        return;
    }

    sending.value = true;
    errors.value = {};

    router.post(
        TableController.addRound.url(props.tab.id),
        {
            notes: roundNotes.value,
            items: cart.items.value.map(cartItemPayload),
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                cart.clear();
                roundNotes.value = '';
                reviewOpen.value = false;
                panel.value = 'bill';
            },
            onError: (bag) => {
                errors.value = bag;
            },
            onFinish: () => {
                sending.value = false;
            },
        },
    );
}

// Tab actions ----------------------------------------------------------------

function requestBill(): void {
    if (props.tab) {
        post(TableController.requestBill.url(props.tab.id));
    }
}

function release(): void {
    if (props.tab && confirm(`Liberar a ${props.table.name} sem cobrar?`)) {
        post(TableController.release.url(props.tab.id));
    }
}

const transferOpen = ref(false);
const transferTo = ref<number | null>(null);

function transfer(): void {
    if (props.tab && transferTo.value !== null) {
        post(
            TableController.transfer.url(props.tab.id),
            { table_id: transferTo.value },
            () => {
                transferOpen.value = false;
            },
        );
    }
}

const joinOpen = ref(false);
const joinWith = ref<number | null>(null);

function join(): void {
    const other = props.joinableTables.find((t) => t.id === joinWith.value);

    if (!props.tab || !other) {
        return;
    }

    if (
        other.occupied &&
        !confirm(
            `A ${other.name} já tem conta aberta. Juntar traz o consumo dela para esta conta. Continuar?`,
        )
    ) {
        return;
    }

    post(TableController.join.url(props.tab.id), { table_id: other.id }, () => {
        joinOpen.value = false;
    });
}

function separate(joined: { id: number; name: string }): void {
    if (
        props.tab &&
        confirm(
            `Separar a ${joined.name}? Ela fica livre e o que já foi consumido continua nesta conta.`,
        )
    ) {
        post(TableController.separate.url(props.tab.id), {
            table_id: joined.id,
        });
    }
}

const roundStatusVariant: Record<
    string,
    'default' | 'secondary' | 'outline' | 'destructive'
> = {
    accepted: 'outline',
    preparing: 'secondary',
    ready: 'default',
    completed: 'secondary',
    cancelled: 'destructive',
};

// Closing ------------------------------------------------------------------

const closeOpen = ref(false);
const withServiceFee = ref(true);
const discount = ref('');
const payments = ref<PaymentLine[]>([]);
const closing = ref(false);

const bill = computed(() => {
    const subtotal = props.tab?.bill.subtotalCents ?? 0;
    const percent = withServiceFee.value
        ? (props.tab?.bill.serviceFeePercent ?? 0)
        : 0;
    const fee = Math.floor((subtotal * percent + 50) / 100);
    const off = Math.min(parseMoney(discount.value) ?? 0, subtotal + fee);

    return { subtotal, percent, fee, off, total: subtotal + fee - off };
});

const perPerson = computed(() => {
    const people = props.tab?.people ?? 0;

    return people > 1 ? Math.ceil(bill.value.total / people) : null;
});

const tally = computed(() => tallyPayments(payments.value, bill.value.total));

function startClose(): void {
    errors.value = {};
    withServiceFee.value = (props.tab?.bill.serviceFeePercent ?? 0) > 0;
    discount.value = '';
    payments.value = [
        {
            method: 'cash',
            amount: centsToInput(props.tab?.bill.totalCents ?? 0),
        },
    ];
    closeOpen.value = true;
}

function closeTab(): void {
    if (!props.tab) {
        return;
    }

    closing.value = true;
    errors.value = {};

    router.post(
        TableController.close.url(props.tab.id),
        {
            service_fee: withServiceFee.value,
            discount: discount.value,
            payments: payments.value,
        },
        {
            onError: (bag) => {
                errors.value = bag;
            },
            onFinish: () => {
                closing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="table.name" />

    <div class="flex flex-col gap-4 p-4 pb-28 lg:pb-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                :title="table.name"
                :description="
                    tab
                        ? `Aberta às ${formatTime(tab.openedAt)}${tab.openedBy ? ` por ${tab.openedBy}` : ''}`
                        : 'Livre'
                "
            />
            <Badge
                v-if="tab"
                :variant="tab.status === 'closing' ? 'default' : 'secondary'"
                class="ml-auto"
            >
                {{ tab.statusLabel }}
            </Badge>
        </div>

        <div
            v-if="tab && tab.joinedTables.length > 0"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 p-3 text-sm"
        >
            <Merge class="size-4 text-primary" />
            <span class="font-medium">Mesas juntas:</span>
            <span
                v-for="joined in tab.joinedTables"
                :key="joined.id"
                class="inline-flex items-center gap-1 rounded-full border bg-background py-0.5 pr-1 pl-3"
            >
                {{ joined.name }}
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-6 px-2 text-xs"
                    @click="separate(joined)"
                >
                    Separar
                </Button>
            </span>
        </div>

        <p
            v-if="firstError"
            class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
        >
            {{ firstError }}
        </p>

        <!-- Free table -->
        <form
            v-if="!tab"
            class="flex max-w-sm flex-col gap-3 rounded-xl border p-4"
            @submit.prevent="openTab"
        >
            <div class="grid grid-cols-[6rem_1fr] gap-2">
                <div class="grid gap-2">
                    <Label for="people">Pessoas</Label>
                    <Input
                        id="people"
                        v-model="openForm.people"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        :placeholder="table.seats ? String(table.seats) : ''"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="customer-name">Nome (opcional)</Label>
                    <Input
                        id="customer-name"
                        v-model="openForm.customer_name"
                        maxlength="80"
                    />
                </div>
            </div>
            <InputError :message="openForm.errors.people ?? tableError" />
            <Button type="submit" size="lg" :disabled="openForm.processing">
                Abrir mesa
            </Button>
        </form>

        <!-- Occupied table -->
        <template v-else>
            <div class="grid grid-cols-2 gap-2 lg:hidden">
                <Button
                    :variant="panel === 'bill' ? 'default' : 'outline'"
                    @click="panel = 'bill'"
                >
                    Consumo
                </Button>
                <Button
                    :variant="panel === 'add' ? 'default' : 'outline'"
                    @click="panel = 'add'"
                >
                    Adicionar itens
                </Button>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1fr_24rem]">
                <section
                    class="flex-col gap-3 lg:flex"
                    :class="panel === 'add' ? 'flex' : 'hidden'"
                >
                    <ProductPicker :categories="categories" @add="cart.add" />
                </section>

                <section
                    class="flex-col gap-3 lg:flex lg:self-start"
                    :class="panel === 'bill' ? 'flex' : 'hidden'"
                >
                    <div
                        v-if="cart.items.value.length > 0"
                        class="hidden flex-col gap-2 rounded-xl border border-primary p-4 lg:flex"
                    >
                        <p class="font-medium">
                            Novo pedido · {{ cart.count.value }} item(ns)
                        </p>
                        <Button @click="reviewOpen = true">
                            Revisar e enviar para a cozinha
                        </Button>
                    </div>

                    <div class="rounded-xl border text-sm">
                        <div
                            class="flex items-center justify-between border-b p-4"
                        >
                            <h3 class="font-medium">Consumo</h3>
                            <span
                                v-if="tab.people"
                                class="flex items-center gap-1 text-muted-foreground"
                            >
                                <Users class="size-4" /> {{ tab.people }}
                            </span>
                        </div>
                        <p
                            v-if="tab.rounds.length === 0"
                            class="p-4 text-muted-foreground"
                        >
                            Nenhum pedido ainda. Toque em "Adicionar itens".
                        </p>
                        <ol v-else class="divide-y">
                            <li
                                v-for="round in tab.rounds"
                                :key="round.id"
                                class="flex flex-col gap-1 p-4"
                                :class="{
                                    'opacity-50': round.status === 'cancelled',
                                }"
                            >
                                <div class="flex items-center gap-2">
                                    <span class="font-medium"
                                        >Pedido nº {{ round.number }}</span
                                    >
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ formatTime(round.placedAt) }}</span
                                    >
                                    <Badge
                                        :variant="
                                            roundStatusVariant[round.status] ??
                                            'outline'
                                        "
                                        class="ml-auto"
                                        >{{ round.statusLabel }}</Badge
                                    >
                                    <Button
                                        v-if="round.editable"
                                        variant="ghost"
                                        size="sm"
                                        class="h-7"
                                        as-child
                                    >
                                        <Link :href="orderEdit(round.id)"
                                            >Alterar</Link
                                        >
                                    </Button>
                                </div>
                                <div
                                    v-for="item in round.items"
                                    :key="item.id"
                                    class="flex justify-between gap-2"
                                >
                                    <span>
                                        {{ item.quantity }}× {{ item.name }}
                                        <span
                                            v-if="item.options"
                                            class="block text-xs text-muted-foreground"
                                            >{{ item.options }}</span
                                        >
                                        <span
                                            v-if="item.notes"
                                            class="block text-xs text-amber-600"
                                            >Obs.: {{ item.notes }}</span
                                        >
                                    </span>
                                    <span class="whitespace-nowrap">{{
                                        formatCents(item.totalCents)
                                    }}</span>
                                </div>
                            </li>
                        </ol>
                        <dl class="grid gap-1 border-t p-4">
                            <div class="flex justify-between">
                                <dt>Consumo</dt>
                                <dd>
                                    {{ formatCents(tab.bill.subtotalCents) }}
                                </dd>
                            </div>
                            <div
                                v-if="tab.bill.serviceFeeCents > 0"
                                class="flex justify-between"
                            >
                                <dt>
                                    Serviço ({{ tab.bill.serviceFeePercent }}%)
                                </dt>
                                <dd>
                                    {{ formatCents(tab.bill.serviceFeeCents) }}
                                </dd>
                            </div>
                            <div
                                class="flex justify-between text-base font-semibold"
                            >
                                <dt>Total</dt>
                                <dd>{{ formatCents(tab.bill.totalCents) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <Button
                            variant="outline"
                            :disabled="tab.bill.subtotalCents === 0"
                            @click="requestBill"
                        >
                            <ReceiptText />
                            Pedir conta
                        </Button>
                        <Button
                            variant="outline"
                            :disabled="freeTables.length === 0"
                            @click="
                                transferTo = freeTables[0]?.id ?? null;
                                transferOpen = true;
                            "
                        >
                            <ArrowLeftRight />
                            Transferir
                        </Button>
                        <Button
                            variant="outline"
                            class="col-span-2"
                            :disabled="joinableTables.length === 0"
                            @click="
                                joinWith = joinableTables[0]?.id ?? null;
                                joinOpen = true;
                            "
                        >
                            <Merge />
                            Juntar mesa
                        </Button>
                        <Button
                            v-if="canClose"
                            class="col-span-2"
                            size="lg"
                            :disabled="tab.bill.subtotalCents === 0"
                            @click="startClose"
                        >
                            Fechar conta
                        </Button>
                        <Button
                            v-if="tab.bill.subtotalCents === 0"
                            variant="ghost"
                            class="col-span-2 text-destructive"
                            @click="release"
                        >
                            Liberar mesa sem consumo
                        </Button>
                    </div>
                </section>
            </div>
        </template>
    </div>

    <!-- Mobile: pending round bar -->
    <div
        v-if="tab && cart.items.value.length > 0"
        class="fixed inset-x-0 bottom-0 z-20 border-t bg-background p-3 lg:hidden"
    >
        <Button
            class="w-full justify-between"
            size="lg"
            @click="reviewOpen = true"
        >
            <span>Revisar pedido ({{ cart.count.value }})</span>
            <span>{{ formatCents(cart.subtotalCents.value) }}</span>
        </Button>
    </div>

    <Dialog v-model:open="reviewOpen">
        <DialogScrollContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Novo pedido · {{ table.name }}</DialogTitle>
                <DialogDescription
                    >Confira antes de enviar para a cozinha.</DialogDescription
                >
            </DialogHeader>
            <ul class="flex flex-col divide-y text-sm">
                <li
                    v-for="item in cart.items.value"
                    :key="item.key"
                    class="flex flex-col gap-1 py-2"
                >
                    <div class="flex justify-between gap-2">
                        <span class="font-medium">{{ item.name }}</span>
                        <span>{{
                            formatCents(item.unitCents * item.quantity)
                        }}</span>
                    </div>
                    <p
                        v-if="cartItemDetails(item)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ cartItemDetails(item) }}
                    </p>
                    <p v-if="item.notes" class="text-xs text-amber-600">
                        Obs.: {{ item.notes }}
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            :aria-label="`Menos ${item.name}`"
                            @click="
                                cart.setQuantity(item.key, item.quantity - 1)
                            "
                        >
                            <Minus />
                        </Button>
                        <span class="w-6 text-center">{{ item.quantity }}</span>
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-8"
                            :aria-label="`Mais ${item.name}`"
                            @click="
                                cart.setQuantity(item.key, item.quantity + 1)
                            "
                        >
                            <Plus />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="ml-auto size-8"
                            :aria-label="`Remover ${item.name}`"
                            @click="cart.remove(item.key)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </li>
            </ul>
            <TextareaField
                v-model="roundNotes"
                maxlength="500"
                placeholder="Observação para a cozinha (opcional)"
                class="min-h-14"
                aria-label="Observação para a cozinha"
            />
            <p
                v-if="firstError"
                class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
            >
                {{ firstError }}
            </p>
            <DialogFooter>
                <Button
                    class="w-full"
                    size="lg"
                    :disabled="sending || cart.items.value.length === 0"
                    @click="sendRound"
                >
                    <Spinner v-if="sending" />
                    Enviar para a cozinha
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>

    <Dialog v-model:open="transferOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Transferir {{ table.name }}</DialogTitle>
                <DialogDescription
                    >A conta inteira vai para a mesa
                    escolhida.</DialogDescription
                >
            </DialogHeader>
            <NativeSelect v-model="transferTo" aria-label="Mesa de destino">
                <option
                    v-for="option in freeTables"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.name }}
                </option>
            </NativeSelect>
            <DialogFooter>
                <Button
                    class="w-full"
                    :disabled="transferTo === null"
                    @click="transfer"
                >
                    Transferir
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="joinOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Juntar mesa à {{ table.name }}</DialogTitle>
                <DialogDescription
                    >O grupo passa a ter uma conta só. Se a mesa escolhida já
                    tiver consumo, ele vem junto para esta
                    conta.</DialogDescription
                >
            </DialogHeader>
            <NativeSelect v-model="joinWith" aria-label="Mesa para juntar">
                <option
                    v-for="option in joinableTables"
                    :key="option.id"
                    :value="option.id"
                >
                    {{ option.name }}{{ option.occupied ? ' (ocupada)' : '' }}
                </option>
            </NativeSelect>
            <DialogFooter>
                <Button
                    class="w-full"
                    :disabled="joinWith === null"
                    @click="join"
                >
                    Juntar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="closeOpen">
        <DialogScrollContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Fechar conta · {{ table.name }}</DialogTitle>
            </DialogHeader>

            <dl class="grid gap-1 text-sm">
                <div class="flex justify-between">
                    <dt>Consumo</dt>
                    <dd>{{ formatCents(bill.subtotal) }}</dd>
                </div>
                <div
                    v-if="(tab?.bill.serviceFeePercent ?? 0) > 0"
                    class="flex items-center justify-between"
                >
                    <dt>
                        <Label class="flex items-center gap-2 font-normal">
                            <Checkbox v-model="withServiceFee" />
                            Serviço ({{ tab?.bill.serviceFeePercent }}%)
                        </Label>
                    </dt>
                    <dd>{{ formatCents(bill.fee) }}</dd>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <dt><Label for="tab-discount">Desconto (R$)</Label></dt>
                    <dd>
                        <Input
                            id="tab-discount"
                            v-model="discount"
                            inputmode="decimal"
                            placeholder="0,00"
                            class="h-8 w-24 text-right"
                        />
                    </dd>
                </div>
                <div
                    class="flex justify-between border-t pt-1 text-base font-semibold"
                >
                    <dt>Total</dt>
                    <dd>{{ formatCents(bill.total) }}</dd>
                </div>
                <div
                    v-if="perPerson"
                    class="flex justify-between text-muted-foreground"
                >
                    <dt>Por pessoa ({{ tab?.people }})</dt>
                    <dd>{{ formatCents(perPerson) }}</dd>
                </div>
            </dl>

            <p
                v-if="!cashOpen"
                class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950"
            >
                O caixa está fechado.
                <Link :href="cashIndex()" class="font-medium underline"
                    >Abrir caixa</Link
                >
            </p>

            <PaymentLines
                v-model="payments"
                :total-cents="bill.total"
                :methods="paymentMethods"
            />

            <p
                v-if="firstError"
                class="rounded-lg bg-destructive/10 p-3 text-sm text-destructive"
            >
                {{ firstError }}
            </p>

            <DialogFooter>
                <Button
                    class="w-full"
                    size="lg"
                    :disabled="closing || !cashOpen || !paymentsReady(tally)"
                    @click="closeTab"
                >
                    <Spinner v-if="closing" />
                    Receber e liberar mesa
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
