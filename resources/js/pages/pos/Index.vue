<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Minus, Plus, Trash2, Wallet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PosController from '@/actions/App/Http/Controllers/Pos/PosController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import PaymentLines from '@/components/pos/PaymentLines.vue';
import ProductPicker from '@/components/pos/ProductPicker.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
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
import { show as posShow } from '@/routes/pos';
import type {
    CartItem,
    DeliveryZoneOption,
    MenuCategory,
    PaymentMethodOption,
} from '@/types/menu';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'PDV', href: posShow() }],
    },
});

const props = defineProps<{
    categories: MenuCategory[];
    deliveryZones: DeliveryZoneOption[];
    paymentMethods: PaymentMethodOption[];
    cashOpen: boolean;
    couriers: { id: number; name: string; role: string }[];
}>();

const page = usePage();
const cart = useCart(`pos:${page.props.currentStore?.slug ?? ''}`);

function optionsLabel(item: CartItem): string {
    return cartItemDetails(item);
}

// Ticket -------------------------------------------------------------------

const fulfillment = ref<'pickup' | 'delivery'>('pickup');
const customer = ref({ name: '', phone: '' });
const address = ref({
    street: '',
    number: '',
    district: '',
    complement: '',
    reference: '',
});
const zoneId = ref<number | null>(null);
const deliveryFee = ref('');
const discount = ref('');
const notes = ref('');
const errors = ref<Record<string, string>>({});

const isDelivery = computed(() => fulfillment.value === 'delivery');

watch(zoneId, (id) => {
    const zone = props.deliveryZones.find((option) => option.id === id);
    deliveryFee.value = zone ? centsToInput(zone.fee_cents) : '';
});

const deliveryFeeCents = computed(() =>
    isDelivery.value ? (parseMoney(deliveryFee.value) ?? 0) : 0,
);
const discountCents = computed(() => parseMoney(discount.value) ?? 0);
const totalCents = computed(() =>
    Math.max(
        0,
        cart.subtotalCents.value + deliveryFeeCents.value - discountCents.value,
    ),
);

// Checkout -----------------------------------------------------------------

const checkoutOpen = ref(false);
const payLater = ref(false);
const laterMethod = ref('cash');
const changeFor = ref('');
const completeNow = ref(true);
const courierId = ref<number | null>(null);
const payments = ref<PaymentLine[]>([]);
const processing = ref(false);

const tally = computed(() => tallyPayments(payments.value, totalCents.value));
const canConfirm = computed(
    () =>
        !processing.value &&
        (payLater.value
            ? laterMethod.value !== ''
            : paymentsReady(tally.value)),
);

function openCheckout(): void {
    errors.value = {};
    payLater.value = isDelivery.value && !props.cashOpen;
    completeNow.value = !isDelivery.value;
    payments.value = [
        { method: 'cash', amount: centsToInput(totalCents.value) },
    ];
    checkoutOpen.value = true;
}

function resetTicket(): void {
    cart.clear();
    fulfillment.value = 'pickup';
    customer.value = { name: '', phone: '' };
    address.value = {
        street: '',
        number: '',
        district: '',
        complement: '',
        reference: '',
    };
    zoneId.value = null;
    deliveryFee.value = '';
    discount.value = '';
    notes.value = '';
    courierId.value = null;
    errors.value = {};
}

function confirm(): void {
    processing.value = true;

    router.post(
        PosController.store.url(),
        {
            fulfillment: fulfillment.value,
            customer: customer.value,
            address: isDelivery.value ? address.value : {},
            delivery_zone_id: isDelivery.value ? zoneId.value : null,
            delivery_fee: isDelivery.value ? deliveryFee.value : null,
            discount: discount.value,
            notes: notes.value,
            pay_later: payLater.value,
            payment_method: payLater.value ? laterMethod.value : null,
            change_for:
                payLater.value && laterMethod.value === 'cash'
                    ? changeFor.value
                    : null,
            payments: payLater.value ? [] : payments.value,
            complete_now: !isDelivery.value && completeNow.value,
            courier_id: isDelivery.value ? courierId.value : null,
            items: cart.items.value.map(cartItemPayload),
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                checkoutOpen.value = false;
                resetTicket();
            },
            onError: (bag) => {
                errors.value = bag;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
}

/** Errors that belong to the ticket, shown in the payment dialog too. */
const firstError = computed(() => Object.values(errors.value)[0] ?? null);
</script>

<template>
    <Head title="PDV" />

    <div class="grid gap-4 p-4 lg:grid-cols-[1fr_24rem]">
        <section class="flex min-w-0 flex-col gap-3">
            <div
                v-if="!cashOpen"
                class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950"
            >
                <Wallet class="size-5 text-amber-600" />
                <span class="flex-1"
                    >O caixa está fechado. Abra o caixa para receber
                    pagamentos.</span
                >
                <Button as-child size="sm">
                    <Link :href="cashIndex()">Abrir caixa</Link>
                </Button>
            </div>

            <ProductPicker :categories="categories" autofocus @add="cart.add" />
        </section>

        <aside
            class="flex flex-col gap-3 rounded-xl border p-4 lg:sticky lg:top-4 lg:max-h-[calc(100svh-6rem)] lg:self-start lg:overflow-y-auto"
        >
            <div class="flex items-center justify-between">
                <h2 class="font-semibold">Comanda</h2>
                <Button
                    v-if="cart.items.value.length > 0"
                    variant="ghost"
                    size="sm"
                    @click="resetTicket"
                >
                    Limpar
                </Button>
            </div>

            <p
                v-if="cart.items.value.length === 0"
                class="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Toque nos produtos para adicionar.
            </p>

            <ul v-else class="flex flex-col divide-y">
                <li
                    v-for="item in cart.items.value"
                    :key="item.key"
                    class="flex flex-col gap-1 py-2 text-sm"
                >
                    <div class="flex items-start justify-between gap-2">
                        <span class="font-medium">{{ item.name }}</span>
                        <span class="whitespace-nowrap">{{
                            formatCents(item.unitCents * item.quantity)
                        }}</span>
                    </div>
                    <p
                        v-if="optionsLabel(item)"
                        class="text-xs text-muted-foreground"
                    >
                        {{ optionsLabel(item) }}
                    </p>
                    <p v-if="item.notes" class="text-xs text-amber-600">
                        Obs.: {{ item.notes }}
                    </p>
                    <div class="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            class="size-7"
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
                            class="size-7"
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
                            class="ml-auto size-7"
                            :aria-label="`Remover ${item.name}`"
                            @click="cart.remove(item.key)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </li>
            </ul>
            <InputError :message="errors.items" />

            <div class="grid grid-cols-2 gap-2">
                <Button
                    :variant="fulfillment === 'pickup' ? 'default' : 'outline'"
                    @click="fulfillment = 'pickup'"
                >
                    Balcão
                </Button>
                <Button
                    :variant="
                        fulfillment === 'delivery' ? 'default' : 'outline'
                    "
                    @click="fulfillment = 'delivery'"
                >
                    Entrega
                </Button>
            </div>

            <div class="grid gap-2">
                <div class="grid grid-cols-2 gap-2">
                    <Input
                        v-model="customer.name"
                        :placeholder="isDelivery ? 'Nome' : 'Nome (opcional)'"
                        aria-label="Nome do cliente"
                    />
                    <Input
                        v-model="customer.phone"
                        inputmode="tel"
                        :placeholder="
                            isDelivery ? 'WhatsApp' : 'WhatsApp (opcional)'
                        "
                        aria-label="WhatsApp do cliente"
                    />
                </div>
                <InputError
                    :message="
                        errors['customer.name'] ?? errors['customer.phone']
                    "
                />
            </div>

            <div v-if="isDelivery" class="grid gap-2">
                <div class="grid grid-cols-[1fr_6rem] gap-2">
                    <NativeSelect
                        v-model="zoneId"
                        aria-label="Região de entrega"
                    >
                        <option :value="null">Região (opcional)</option>
                        <option
                            v-for="zone in deliveryZones"
                            :key="zone.id"
                            :value="zone.id"
                        >
                            {{ zone.name }} · {{ formatCents(zone.fee_cents) }}
                        </option>
                    </NativeSelect>
                    <Input
                        v-model="deliveryFee"
                        inputmode="decimal"
                        placeholder="Taxa"
                        aria-label="Taxa de entrega"
                        class="text-right"
                    />
                </div>
                <div class="grid grid-cols-[1fr_5rem] gap-2">
                    <Input
                        v-model="address.street"
                        placeholder="Rua"
                        aria-label="Rua"
                    />
                    <Input
                        v-model="address.number"
                        placeholder="Nº"
                        aria-label="Número"
                    />
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <Input
                        v-model="address.district"
                        placeholder="Bairro"
                        aria-label="Bairro"
                    />
                    <Input
                        v-model="address.complement"
                        placeholder="Complemento"
                        aria-label="Complemento"
                    />
                </div>
                <Input
                    v-model="address.reference"
                    placeholder="Ponto de referência"
                    aria-label="Ponto de referência"
                />
                <InputError
                    :message="
                        errors['address.street'] ?? errors.delivery_zone_id
                    "
                />
            </div>

            <div class="grid grid-cols-[1fr_7rem] items-center gap-2">
                <Label for="pos-discount">Desconto (R$)</Label>
                <Input
                    id="pos-discount"
                    v-model="discount"
                    inputmode="decimal"
                    placeholder="0,00"
                    class="text-right"
                />
            </div>
            <InputError :message="errors.discount" />

            <TextareaField
                v-model="notes"
                maxlength="500"
                placeholder="Observações do pedido"
                class="min-h-14"
                aria-label="Observações do pedido"
            />

            <dl class="grid gap-1 border-t pt-3 text-sm">
                <div class="flex justify-between">
                    <dt>Subtotal</dt>
                    <dd>{{ formatCents(cart.subtotalCents.value) }}</dd>
                </div>
                <div v-if="deliveryFeeCents > 0" class="flex justify-between">
                    <dt>Entrega</dt>
                    <dd>{{ formatCents(deliveryFeeCents) }}</dd>
                </div>
                <div v-if="discountCents > 0" class="flex justify-between">
                    <dt>Desconto</dt>
                    <dd>− {{ formatCents(discountCents) }}</dd>
                </div>
                <div class="flex justify-between text-lg font-semibold">
                    <dt>Total</dt>
                    <dd>{{ formatCents(totalCents) }}</dd>
                </div>
            </dl>

            <Button
                size="lg"
                :disabled="cart.items.value.length === 0"
                @click="openCheckout"
            >
                Cobrar {{ formatCents(totalCents) }}
            </Button>
        </aside>
    </div>

    <Dialog v-model:open="checkoutOpen">
        <DialogScrollContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Pagamento · {{ formatCents(totalCents) }}</DialogTitle
                >
                <DialogDescription>
                    {{ isDelivery ? 'Entrega' : 'Balcão' }}
                    <template v-if="customer.name">
                        · {{ customer.name }}</template
                    >
                </DialogDescription>
            </DialogHeader>

            <div v-if="isDelivery" class="grid grid-cols-2 gap-2">
                <Button
                    :variant="!payLater ? 'default' : 'outline'"
                    :disabled="!cashOpen"
                    @click="payLater = false"
                >
                    Receber agora
                </Button>
                <Button
                    :variant="payLater ? 'default' : 'outline'"
                    @click="payLater = true"
                >
                    Na entrega
                </Button>
            </div>

            <div v-if="isDelivery && couriers.length > 0" class="grid gap-2">
                <Label for="pos-courier">Entregador (opcional)</Label>
                <NativeSelect id="pos-courier" v-model="courierId">
                    <option :value="null">Definir depois</option>
                    <option
                        v-for="courier in couriers"
                        :key="courier.id"
                        :value="courier.id"
                    >
                        {{ courier.name }} ({{ courier.role }})
                    </option>
                </NativeSelect>
            </div>

            <div v-if="payLater" class="grid gap-3">
                <div class="grid gap-2">
                    <Label for="later-method"
                        >Forma de pagamento na entrega</Label
                    >
                    <NativeSelect id="later-method" v-model="laterMethod">
                        <option
                            v-for="method in paymentMethods"
                            :key="method.value"
                            :value="method.value"
                        >
                            {{ method.label }}
                        </option>
                    </NativeSelect>
                </div>
                <div v-if="laterMethod === 'cash'" class="grid gap-2">
                    <Label for="later-change">Troco para (opcional)</Label>
                    <Input
                        id="later-change"
                        v-model="changeFor"
                        inputmode="decimal"
                        placeholder="0,00"
                    />
                </div>
                <p class="text-xs text-muted-foreground">
                    O valor entra no caixa quando o pedido for concluído.
                </p>
            </div>

            <template v-else>
                <p
                    v-if="!cashOpen"
                    class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950"
                >
                    Abra o caixa para receber pagamentos.
                </p>
                <PaymentLines
                    v-model="payments"
                    :total-cents="totalCents"
                    :methods="paymentMethods"
                />
                <Label
                    v-if="!isDelivery"
                    class="flex items-center gap-2 text-sm font-normal"
                >
                    <Checkbox v-model="completeNow" />
                    Cliente já levou o pedido (concluir agora)
                </Label>
            </template>

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
                    :disabled="!canConfirm || (!payLater && !cashOpen)"
                    @click="confirm"
                >
                    <Spinner v-if="processing" />
                    Confirmar venda
                </Button>
            </DialogFooter>
        </DialogScrollContent>
    </Dialog>
</template>
