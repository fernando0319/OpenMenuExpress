<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Minus, Plus, Trash2, Undo2 } from '@lucide/vue';
import { computed, ref, watchEffect } from 'vue';
import OrderEditController from '@/actions/App/Http/Controllers/Orders/OrderEditController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ProductPicker from '@/components/pos/ProductPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cartItemDetails } from '@/lib/cart';
import { formatCents, parseMoney } from '@/lib/money';
import { index, show } from '@/routes/orders';
import { show as tableShow } from '@/routes/tables';
import type { CartItem, MenuCategory, PaymentMethodOption } from '@/types/menu';

type ExistingItem = {
    id: number;
    name: string;
    quantity: number;
    unitCents: number;
    notes: string;
    options: string[];
};

type Line =
    | (ExistingItem & {
          key: string;
          kind: 'existing';
          removed: boolean;
          originalQuantity: number;
          originalNotes: string;
      })
    | {
          key: string;
          kind: 'new';
          productId: number;
          name: string;
          quantity: number;
          unitCents: number;
          notes: string;
          options: string[];
          optionInput: { option_id: number; quantity: number }[];
          pizza: { flavor_ids: number[]; crust_id: number | null } | null;
          removed: false;
      };

const props = defineProps<{
    order: {
        id: number;
        number: number;
        statusLabel: string;
        fulfillment: 'delivery' | 'pickup' | 'dine_in';
        fulfillmentLabel: string;
        tableName: string | null;
        tableId: number | null;
        customerName: string;
        customerPhone: string | null;
        address: Record<
            | 'zip'
            | 'street'
            | 'number'
            | 'district'
            | 'complement'
            | 'reference',
            string
        >;
        deliveryFee: string;
        discount: string;
        couponCode: string | null;
        paymentMethod: string;
        paymentLabel: string;
        changeFor: string;
        notes: string;
        totalCents: number;
        receivedCents: number;
        items: ExistingItem[];
    };
    categories: MenuCategory[];
    paymentMethods: PaymentMethodOption[];
    cashOpen: boolean;
    canNotify: boolean;
}>();

/** 5542999998888 → (42) 99999-8888, easier to read and check in the field. */
function formatPhone(phone: string | null): string {
    if (!phone) {
        return '';
    }

    const local = phone.replace(/^55/, '');

    return local.length >= 10
        ? `(${local.slice(0, 2)}) ${local.slice(2, -4)}-${local.slice(-4)}`
        : phone;
}

const isTab = computed(() => props.order.tableId !== null);
const isDelivery = computed(() => props.order.fulfillment === 'delivery');
const backHref = computed(() =>
    props.order.tableId !== null
        ? tableShow(props.order.tableId)
        : show(props.order.id),
);

watchEffect(() => {
    setLayoutProps({
        breadcrumbs: [
            { title: 'Pedidos', href: index() },
            {
                title: `Pedido nº ${props.order.number}`,
                href: show(props.order.id),
            },
            { title: 'Alterar', href: '#' },
        ],
    });
});

const lines = ref<Line[]>(
    props.order.items.map((item) => ({
        ...item,
        key: `item-${item.id}`,
        kind: 'existing' as const,
        removed: false,
        originalQuantity: item.quantity,
        originalNotes: item.notes,
    })),
);

let nextKey = 0;

function add(item: Omit<CartItem, 'key'>): void {
    lines.value.push({
        key: `new-${nextKey++}`,
        kind: 'new',
        productId: item.productId,
        name: item.name,
        quantity: item.quantity,
        unitCents: item.unitCents,
        notes: item.notes,
        options: cartItemDetails(item) ? [cartItemDetails(item)] : [],
        optionInput: item.options.map((option) => ({
            option_id: option.optionId,
            quantity: option.quantity,
        })),
        pizza: item.pizza
            ? { flavor_ids: item.pizza.flavorIds, crust_id: item.pizza.crustId }
            : null,
        removed: false,
    });
}

function step(line: Line, delta: number): void {
    line.quantity = Math.max(1, Math.min(99, line.quantity + delta));
}

function remove(line: Line): void {
    if (line.kind === 'new') {
        lines.value = lines.value.filter((other) => other.key !== line.key);

        return;
    }

    line.removed = !line.removed;
}

const form = useForm({
    customer: {
        name: props.order.customerName,
        phone: formatPhone(props.order.customerPhone),
    },
    address: { ...props.order.address },
    delivery_fee: props.order.deliveryFee,
    discount: props.order.discount,
    payment_method: props.order.paymentMethod,
    change_for: props.order.changeFor,
    notes: props.order.notes,
    reason: '',
    notify_customer: props.canNotify,
    refund_difference: props.cashOpen,
});

const activeLines = computed(() => lines.value.filter((line) => !line.removed));
const subtotalCents = computed(() =>
    activeLines.value.reduce(
        (sum, line) => sum + line.unitCents * line.quantity,
        0,
    ),
);
const feeCents = computed(() =>
    isDelivery.value ? (parseMoney(form.delivery_fee) ?? 0) : 0,
);
const discountCents = computed(() => parseMoney(form.discount) ?? 0);
const totalCents = computed(
    () => subtotalCents.value + feeCents.value - discountCents.value,
);
const differenceCents = computed(
    () => totalCents.value - props.order.totalCents,
);
const paid = computed(() => !isTab.value && props.order.receivedCents > 0);
const balanceCents = computed(
    () => totalCents.value - props.order.receivedCents,
);
const canChoosePayment = computed(
    () =>
        !isTab.value &&
        props.order.receivedCents === 0 &&
        props.order.paymentMethod !== 'split',
);

function lineChanged(line: Line): boolean {
    return (
        line.kind === 'new' ||
        line.removed ||
        line.quantity !== line.originalQuantity ||
        line.notes !== line.originalNotes
    );
}

const itemErrors = computed(() =>
    Object.entries(form.errors)
        .filter(([key]) => key === 'items' || key.startsWith('items.'))
        .map(([, message]) => message),
);

function submit(): void {
    form.transform((data) => ({
        ...data,
        customer: isTab.value ? undefined : data.customer,
        address: isDelivery.value ? data.address : undefined,
        payment_method: canChoosePayment.value ? data.payment_method : null,
        change_for:
            canChoosePayment.value && data.payment_method === 'cash'
                ? data.change_for
                : '',
        items: activeLines.value.map((line) =>
            line.kind === 'existing'
                ? { id: line.id, quantity: line.quantity, notes: line.notes }
                : {
                      product_id: line.productId,
                      quantity: line.quantity,
                      notes: line.notes,
                      options: line.optionInput,
                      pizza: line.pizza,
                  },
        ),
    })).put(OrderEditController.update.url(props.order.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Alterar pedido ${order.number}`" />

    <div class="grid gap-6 p-4 md:p-6 lg:grid-cols-[1fr_24rem]">
        <section class="flex min-w-0 flex-col gap-4">
            <Heading
                :title="`Alterar pedido nº ${order.number}`"
                :description="`${order.fulfillmentLabel}${order.tableName ? ` · ${order.tableName}` : ''} · ${order.statusLabel}. Os itens que já estavam no pedido mantêm o preço da venda.`"
            />

            <div class="rounded-2xl border bg-card shadow-xs">
                <h2 class="border-b px-4 py-3 font-semibold">
                    Itens do pedido
                </h2>
                <ul class="flex flex-col divide-y">
                    <li
                        v-for="line in lines"
                        :key="line.key"
                        class="flex flex-col gap-2 p-4"
                        :class="{ 'bg-muted/40': line.removed }"
                    >
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="font-medium"
                                    :class="{
                                        'text-muted-foreground line-through':
                                            line.removed,
                                    }"
                                >
                                    {{ line.name }}
                                    <Badge
                                        v-if="line.kind === 'new'"
                                        variant="secondary"
                                        class="ml-1"
                                        >novo</Badge
                                    >
                                    <Badge
                                        v-else-if="line.removed"
                                        variant="destructive"
                                        class="ml-1"
                                        >será removido</Badge
                                    >
                                    <Badge
                                        v-else-if="lineChanged(line)"
                                        variant="outline"
                                        class="ml-1"
                                        >alterado</Badge
                                    >
                                </p>
                                <p
                                    v-if="line.options.length"
                                    class="text-sm text-muted-foreground"
                                >
                                    {{ line.options.join(', ') }}
                                </p>
                            </div>
                            <span
                                class="text-sm font-medium whitespace-nowrap tabular-nums"
                                >{{
                                    formatCents(line.unitCents * line.quantity)
                                }}</span
                            >
                        </div>
                        <div
                            v-if="!line.removed"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <div class="flex items-center rounded-lg border">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :aria-label="`Diminuir ${line.name}`"
                                    :disabled="line.quantity <= 1"
                                    @click="step(line, -1)"
                                    ><Minus
                                /></Button>
                                <span
                                    class="w-8 text-center text-sm font-medium tabular-nums"
                                    aria-live="polite"
                                    >{{ line.quantity }}</span
                                >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-8"
                                    :aria-label="`Aumentar ${line.name}`"
                                    @click="step(line, 1)"
                                    ><Plus
                                /></Button>
                            </div>
                            <Input
                                v-model="line.notes"
                                class="h-8 min-w-40 flex-1"
                                maxlength="200"
                                placeholder="Observação (ex.: sem cebola)"
                                :aria-label="`Observação de ${line.name}`"
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                @click="remove(line)"
                            >
                                <Trash2 /> Remover
                            </Button>
                        </div>
                        <Button
                            v-else
                            variant="ghost"
                            size="sm"
                            class="self-start"
                            @click="remove(line)"
                        >
                            <Undo2 /> Manter item
                        </Button>
                    </li>
                </ul>
                <div v-if="itemErrors.length > 0" class="border-t p-4">
                    <InputError
                        v-for="message in itemErrors"
                        :key="message"
                        :message="message"
                    />
                </div>
            </div>

            <div class="rounded-2xl border bg-card p-4 shadow-xs">
                <h2 class="mb-3 font-semibold">Adicionar itens</h2>
                <ProductPicker :categories="categories" @add="add" />
            </div>
        </section>

        <aside class="flex flex-col gap-4 text-sm">
            <div
                v-if="!isTab"
                class="grid gap-3 rounded-2xl border bg-card p-4 shadow-xs"
            >
                <h2 class="font-semibold">Cliente</h2>
                <div class="grid gap-1.5">
                    <Label for="customer-name">Nome</Label>
                    <Input
                        id="customer-name"
                        v-model="form.customer.name"
                        maxlength="80"
                    />
                    <InputError :message="form.errors['customer.name']" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="customer-phone">WhatsApp</Label>
                    <Input
                        id="customer-phone"
                        v-model="form.customer.phone"
                        inputmode="tel"
                    />
                    <InputError :message="form.errors['customer.phone']" />
                </div>
            </div>

            <div
                v-if="isDelivery"
                class="grid gap-3 rounded-2xl border bg-card p-4 shadow-xs"
            >
                <h2 class="font-semibold">Entrega</h2>
                <div class="grid grid-cols-[1fr_6rem] gap-2">
                    <div class="grid gap-1.5">
                        <Label for="street">Rua</Label>
                        <Input
                            id="street"
                            v-model="form.address.street"
                            maxlength="120"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="number">Número</Label>
                        <Input
                            id="number"
                            v-model="form.address.number"
                            maxlength="20"
                        />
                    </div>
                </div>
                <InputError :message="form.errors['address.street']" />
                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-1.5">
                        <Label for="district">Bairro</Label>
                        <Input
                            id="district"
                            v-model="form.address.district"
                            maxlength="80"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="complement">Complemento</Label>
                        <Input
                            id="complement"
                            v-model="form.address.complement"
                            maxlength="80"
                        />
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="reference">Referência</Label>
                    <Input
                        id="reference"
                        v-model="form.address.reference"
                        maxlength="120"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="fee">Taxa de entrega (R$)</Label>
                    <Input
                        id="fee"
                        v-model="form.delivery_fee"
                        inputmode="decimal"
                    />
                    <InputError :message="form.errors.delivery_fee" />
                </div>
            </div>

            <div class="grid gap-3 rounded-2xl border bg-card p-4 shadow-xs">
                <h2 class="font-semibold">Valores e pagamento</h2>
                <div class="grid gap-1.5">
                    <Label for="discount">
                        Desconto (R$)
                        <span
                            v-if="order.couponCode"
                            class="font-normal text-muted-foreground"
                            >· cupom {{ order.couponCode }}</span
                        >
                    </Label>
                    <Input
                        id="discount"
                        v-model="form.discount"
                        inputmode="decimal"
                        placeholder="0,00"
                    />
                    <InputError :message="form.errors.discount" />
                </div>
                <template v-if="canChoosePayment">
                    <div class="grid gap-1.5">
                        <Label for="payment">Forma de pagamento</Label>
                        <NativeSelect
                            id="payment"
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
                    </div>
                    <div
                        v-if="form.payment_method === 'cash'"
                        class="grid gap-1.5"
                    >
                        <Label for="change">Troco para (R$)</Label>
                        <Input
                            id="change"
                            v-model="form.change_for"
                            inputmode="decimal"
                            placeholder="Sem troco"
                        />
                        <InputError :message="form.errors.change_for" />
                    </div>
                </template>
                <p v-else-if="!isTab" class="text-muted-foreground">
                    Pagamento: {{ order.paymentLabel }}
                </p>
                <div class="grid gap-1.5">
                    <Label for="notes">Observações do pedido</Label>
                    <Input id="notes" v-model="form.notes" maxlength="500" />
                </div>
            </div>

            <div class="grid gap-3 rounded-2xl border bg-card p-4 shadow-xs">
                <dl class="grid gap-1">
                    <div class="flex justify-between text-muted-foreground">
                        <dt>Total antes</dt>
                        <dd class="tabular-nums">
                            {{ formatCents(order.totalCents) }}
                        </dd>
                    </div>
                    <div class="flex justify-between text-base font-semibold">
                        <dt>Novo total</dt>
                        <dd class="tabular-nums">
                            {{ formatCents(totalCents) }}
                        </dd>
                    </div>
                    <div
                        v-if="differenceCents !== 0"
                        class="flex justify-between text-muted-foreground"
                    >
                        <dt>Diferença</dt>
                        <dd class="tabular-nums">
                            {{ differenceCents > 0 ? '+' : '−'
                            }}{{ formatCents(Math.abs(differenceCents)) }}
                        </dd>
                    </div>
                </dl>

                <p
                    v-if="paid && balanceCents > 0"
                    class="rounded-lg bg-amber-500/10 p-2 text-amber-800 dark:text-amber-300"
                >
                    O cliente já pagou {{ formatCents(order.receivedCents) }}.
                    Vai faltar receber
                    <strong>{{ formatCents(balanceCents) }}</strong
                    >.
                </p>
                <template v-else-if="paid && balanceCents < 0">
                    <p
                        class="rounded-lg bg-amber-500/10 p-2 text-amber-800 dark:text-amber-300"
                    >
                        O cliente já pagou
                        {{ formatCents(order.receivedCents) }}: sobram
                        <strong>{{ formatCents(-balanceCents) }}</strong> para
                        devolver.
                    </p>
                    <Label class="flex items-start gap-2 font-normal">
                        <Checkbox
                            v-model="form.refund_difference"
                            :disabled="!cashOpen"
                            class="mt-0.5"
                        />
                        <span>
                            Lançar o estorno no caixa agora
                            <span
                                v-if="!cashOpen"
                                class="block text-xs text-muted-foreground"
                                >O caixa está fechado; devolva e ajuste o caixa
                                depois.</span
                            >
                        </span>
                    </Label>
                </template>

                <div class="grid gap-1.5">
                    <Label for="reason">Motivo (opcional)</Label>
                    <Input
                        id="reason"
                        v-model="form.reason"
                        maxlength="255"
                        placeholder="Ex.: cliente trocou o refrigerante"
                    />
                </div>

                <Label
                    v-if="canNotify && form.customer.phone"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox v-model="form.notify_customer" />
                    Avisar o cliente pelo WhatsApp
                </Label>

                <div class="flex gap-2">
                    <Button
                        class="flex-1"
                        :disabled="form.processing || activeLines.length === 0"
                        @click="submit"
                    >
                        Salvar alteração
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="backHref">Voltar</Link>
                    </Button>
                </div>
                <p
                    v-if="activeLines.length === 0"
                    class="text-xs text-destructive"
                >
                    O pedido precisa de pelo menos um item. Para desistir de
                    tudo, cancele o pedido.
                </p>
            </div>
        </aside>
    </div>
</template>
