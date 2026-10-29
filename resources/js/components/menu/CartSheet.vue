<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Bike,
    ChevronLeft,
    Minus,
    Plus,
    ShoppingBasket,
    Store as StoreIcon,
    Trash2,
    UtensilsCrossed,
} from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, ref, watch } from 'vue';
import PublicMenuController from '@/actions/App/Http/Controllers/PublicMenuController';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson } from '@/lib/http';
import { cartItemDetails, cartItemPayload } from '@/lib/cart';
import { formatCents } from '@/lib/money';
import type {
    CartItem,
    CouponPreview,
    DeliveryZoneOption,
    MenuStore,
    PaymentMethodOption,
    RememberedCustomer,
    ScheduleSlot,
} from '@/types/menu';

const props = defineProps<{
    store: MenuStore;
    items: CartItem[];
    subtotalCents: number;
    deliveryZones: DeliveryZoneOption[];
    paymentMethods: PaymentMethodOption[];
    /** Paid now in the app (Pix, card, Bitcoin), straight to the store's account. */
    onlinePaymentMethods?: PaymentMethodOption[];
    customer: RememberedCustomer | null;
    slots: ScheduleSlot[];
    bannerCoupon: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    setQuantity: [key: string, quantity: number];
    remove: [key: string];
    placed: [];
}>();

const fulfillments = computed(() =>
    [
        props.store.acceptsDelivery && props.deliveryZones.length > 0
            ? { value: 'delivery', label: 'Entrega', hint: 'No seu endereço' }
            : null,
        props.store.acceptsPickup
            ? { value: 'pickup', label: 'Retirada', hint: 'No balcão da loja' }
            : null,
    ].filter((option) => option !== null),
);

const form = useForm({
    fulfillment: fulfillments.value[0]?.value ?? 'pickup',
    customer: {
        name: props.customer?.name ?? '',
        phone: props.customer?.phone?.replace(/^55/, '') ?? '',
    },
    address: {
        zip: props.customer?.address?.zip ?? '',
        street: props.customer?.address?.street ?? '',
        number: props.customer?.address?.number ?? '',
        district: props.customer?.address?.district ?? '',
        complement: props.customer?.address?.complement ?? '',
        reference: props.customer?.address?.reference ?? '',
    },
    delivery_zone_id: props.customer?.delivery_zone_id ?? null,
    payment_method: '',
    change_for: '',
    // Paying cash, the customer must answer: null = not answered yet.
    no_change: null as boolean | null,
    notes: '',
    // Closed store: the order must be booked. Open store: "now" unless the customer picks a time.
    when: props.store.isOpen ? 'now' : 'scheduled',
    scheduled_for: '',
    items: [] as {
        product_id: number;
        quantity: number;
        notes: string;
        options: { option_id: number; quantity: number }[];
    }[],
});

const isDelivery = computed(() => form.fulfillment === 'delivery');

const deliveryFeeCents = computed(() => {
    if (!isDelivery.value) {
        return 0;
    }

    return (
        props.deliveryZones.find((zone) => zone.id === form.delivery_zone_id)
            ?.fee_cents ?? 0
    );
});

// Coupon preview: the server answers with the discount; the order re-checks it.
const couponInput = ref('');
const coupon = ref<CouponPreview | null>(null);
const couponError = ref<string | null>(null);
const checkingCoupon = ref(false);

async function applyCoupon(
    code = couponInput.value,
    quiet = false,
): Promise<void> {
    if (code.trim() === '') {
        return;
    }

    checkingCoupon.value = true;

    const { ok, data } = await postJson<
        CouponPreview & { valid: boolean; message?: string }
    >(PublicMenuController.checkCoupon.url(props.store.slug), {
        code,
        subtotal_cents: props.subtotalCents,
        fulfillment: form.fulfillment,
        delivery_zone_id: isDelivery.value ? form.delivery_zone_id : null,
        phone: form.customer.phone,
    });

    checkingCoupon.value = false;

    if (ok && data.valid) {
        coupon.value = data;
        couponInput.value = data.code;
        couponError.value = null;

        return;
    }

    coupon.value = null;
    couponError.value =
        quiet && !data.message
            ? null
            : (data.message ?? 'Não foi possível validar o cupom.');
}

function removeCoupon(): void {
    coupon.value = null;
    couponInput.value = '';
    couponError.value = null;
}

// The discount depends on the cart, the delivery choice and the customer: re-check when they change.
watch(
    () => [props.subtotalCents, form.fulfillment, form.delivery_zone_id],
    () => {
        if (coupon.value) {
            void applyCoupon(coupon.value.code, true);
        }
    },
);

watch(
    () => [open.value, props.bannerCoupon] as const,
    ([isOpen, code]) => {
        if (isOpen && code && coupon.value?.code !== code) {
            couponInput.value = code;
            void applyCoupon(code);
        }
    },
    { immediate: true },
);

const discountCents = computed(() => coupon.value?.discountCents ?? 0);

const totalCents = computed(
    () => props.subtotalCents + deliveryFeeCents.value - discountCents.value,
);

const belowMinimum = computed(
    () => props.subtotalCents < props.store.minimumOrderCents,
);

const isScheduling = computed(() => form.when === 'scheduled');

const slotsByDay = computed(() =>
    props.slots.reduce<Record<string, ScheduleSlot[]>>((days, slot) => {
        (days[slot.day] ??= []).push(slot);

        return days;
    }, {}),
);

const canSubmit = computed(
    () =>
        !form.processing &&
        !belowMinimum.value &&
        (isScheduling.value ? form.scheduled_for !== '' : props.store.isOpen),
);

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

const itemErrors = computed(() =>
    Object.entries(form.errors as Record<string, string>)
        .filter(([key]) => key === 'items' || key.startsWith('items.'))
        .map(([, message]) => message),
);

function optionsLabel(item: CartItem): string {
    return cartItemDetails(item);
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        items: props.items.map(cartItemPayload),
        address: isDelivery.value ? data.address : null,
        delivery_zone_id: isDelivery.value ? data.delivery_zone_id : null,
        change_for:
            data.payment_method === 'cash' && data.no_change === false
                ? data.change_for
                : null,
        no_change: data.payment_method === 'cash' && data.no_change === true,
        scheduled_for: data.when === 'scheduled' ? data.scheduled_for : null,
        coupon_code: coupon.value?.code ?? null,
    })).post(PublicMenuController.placeOrder.url(props.store.slug), {
        preserveScroll: true,
        onSuccess: () => emit('placed'),
    });
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-slate-900/50 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="fixed inset-0 z-50 flex flex-col bg-brand-surface font-display text-brand-ink shadow-2xl data-[state=closed]:animate-out data-[state=closed]:slide-out-to-right data-[state=open]:animate-in data-[state=open]:slide-in-from-right sm:left-auto sm:w-full sm:max-w-md"
            >
                <header
                    class="flex items-center gap-3 rounded-b-[2rem] bg-white px-4 pt-[max(1rem,env(safe-area-inset-top))] pb-4 shadow-soft"
                >
                    <DialogClose
                        class="grid size-10 place-items-center rounded-full text-brand-ink hover:bg-brand-surface"
                        aria-label="Voltar ao cardápio"
                    >
                        <ChevronLeft class="size-6" />
                    </DialogClose>
                    <div class="flex-1 pr-10 text-center">
                        <DialogTitle class="text-xl font-semibold text-brand"
                            >Carrinho</DialogTitle
                        >
                        <DialogDescription
                            class="text-xs text-muted-foreground"
                            >{{ store.name }}</DialogDescription
                        >
                    </div>
                </header>

                <div
                    v-if="items.length === 0"
                    class="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center"
                >
                    <span
                        class="grid size-20 place-items-center rounded-full bg-white text-brand shadow-soft"
                    >
                        <ShoppingBasket class="size-9" />
                    </span>
                    <p class="font-medium">Seu carrinho está vazio</p>
                    <p class="text-sm text-muted-foreground">
                        Escolha os produtos no cardápio para começar.
                    </p>
                    <DialogClose
                        class="mt-2 h-12 rounded-2xl bg-linear-to-r from-brand to-brand-accent px-6 font-medium text-white"
                    >
                        Ver o cardápio
                    </DialogClose>
                </div>

                <form
                    v-else
                    class="flex flex-1 flex-col overflow-hidden"
                    @submit.prevent="submit"
                >
                    <div class="flex-1 overflow-y-auto px-4 py-5">
                        <div class="flex flex-col gap-5">
                            <section
                                v-if="fulfillments.length > 0"
                                class="grid grid-cols-2 gap-3"
                            >
                                <button
                                    v-for="option in fulfillments"
                                    :key="option.value"
                                    type="button"
                                    class="flex items-center gap-2 rounded-2xl px-3 py-3 text-left transition-colors"
                                    :class="
                                        form.fulfillment === option.value
                                            ? 'bg-brand text-white shadow-lg shadow-brand/25'
                                            : 'bg-white text-brand-ink shadow-soft'
                                    "
                                    :aria-pressed="
                                        form.fulfillment === option.value
                                    "
                                    @click="form.fulfillment = option.value"
                                >
                                    <component
                                        :is="
                                            option.value === 'delivery'
                                                ? Bike
                                                : StoreIcon
                                        "
                                        class="size-5 shrink-0"
                                    />
                                    <span class="min-w-0">
                                        <span
                                            class="block text-sm font-semibold"
                                            >{{ option.label }}</span
                                        >
                                        <span
                                            class="block truncate text-[11px]"
                                            :class="
                                                form.fulfillment ===
                                                option.value
                                                    ? 'text-white/80'
                                                    : 'text-muted-foreground'
                                            "
                                            >{{ option.hint }}</span
                                        >
                                    </span>
                                </button>
                            </section>
                            <InputError :message="errorFor('fulfillment')" />

                            <ul class="flex flex-col gap-3">
                                <li
                                    v-for="item in items"
                                    :key="item.key"
                                    class="relative flex items-center gap-3 rounded-3xl bg-white p-3 pr-4 shadow-soft"
                                >
                                    <span
                                        class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-full bg-brand-surface shadow-md ring-4 ring-white"
                                    >
                                        <img
                                            v-if="item.imageUrl"
                                            :src="item.imageUrl"
                                            :alt="item.name"
                                            class="size-full object-cover"
                                        />
                                        <UtensilsCrossed
                                            v-else
                                            class="size-6 text-brand/40"
                                        />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="truncate pr-7 font-semibold text-brand"
                                        >
                                            {{ item.name }}
                                        </p>
                                        <p
                                            v-if="optionsLabel(item)"
                                            class="line-clamp-2 text-xs text-muted-foreground"
                                        >
                                            {{ optionsLabel(item) }}
                                        </p>
                                        <p
                                            v-if="item.notes"
                                            class="line-clamp-1 text-xs text-muted-foreground italic"
                                        >
                                            Obs.: {{ item.notes }}
                                        </p>
                                        <div
                                            class="mt-2 flex items-center gap-2"
                                        >
                                            <button
                                                type="button"
                                                class="grid size-7 place-items-center rounded-lg bg-brand-surface text-brand-ink"
                                                aria-label="Diminuir"
                                                @click="
                                                    emit(
                                                        'setQuantity',
                                                        item.key,
                                                        item.quantity - 1,
                                                    )
                                                "
                                            >
                                                <Minus class="size-3.5" />
                                            </button>
                                            <span
                                                class="w-5 text-center text-sm font-medium"
                                                >{{ item.quantity }}</span
                                            >
                                            <button
                                                type="button"
                                                class="grid size-7 place-items-center rounded-lg bg-brand text-white"
                                                aria-label="Aumentar"
                                                @click="
                                                    emit(
                                                        'setQuantity',
                                                        item.key,
                                                        item.quantity + 1,
                                                    )
                                                "
                                            >
                                                <Plus class="size-3.5" />
                                            </button>
                                            <span
                                                class="ml-auto font-semibold text-brand-accent"
                                                >{{
                                                    formatCents(
                                                        item.unitCents *
                                                            item.quantity,
                                                    )
                                                }}</span
                                            >
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        class="absolute -top-2 -right-1 grid size-8 place-items-center rounded-full bg-rose-500 text-white shadow-md shadow-rose-500/30"
                                        :aria-label="`Remover ${item.name}`"
                                        @click="emit('remove', item.key)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </li>
                            </ul>

                            <InputError
                                v-for="message in itemErrors"
                                :key="message"
                                :message="message"
                            />
                            <InputError :message="errorFor('store')" />

                            <section
                                class="grid gap-4 rounded-3xl bg-white p-5 shadow-soft"
                            >
                                <h3 class="font-semibold">Seus dados</h3>
                                <div class="grid gap-2">
                                    <Label for="customer-name">Nome</Label>
                                    <Input
                                        id="customer-name"
                                        v-model="form.customer.name"
                                        class="h-11 rounded-xl"
                                        autocomplete="name"
                                        required
                                    />
                                    <InputError
                                        :message="errorFor('customer.name')"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="customer-phone">WhatsApp</Label>
                                    <Input
                                        id="customer-phone"
                                        v-model="form.customer.phone"
                                        class="h-11 rounded-xl"
                                        type="tel"
                                        inputmode="tel"
                                        autocomplete="tel-national"
                                        placeholder="(11) 99999-8888"
                                        required
                                    />
                                    <p class="text-xs text-muted-foreground">
                                        Você recebe as atualizações do pedido
                                        por aqui. Não precisa criar conta.
                                    </p>
                                    <InputError
                                        :message="errorFor('customer.phone')"
                                    />
                                </div>
                            </section>

                            <section
                                v-if="isDelivery"
                                class="grid gap-4 rounded-3xl bg-white p-5 shadow-soft"
                            >
                                <h3 class="font-semibold">
                                    Endereço de entrega
                                </h3>
                                <div class="grid gap-2">
                                    <Label for="zone">Bairro / região</Label>
                                    <NativeSelect
                                        id="zone"
                                        v-model="form.delivery_zone_id"
                                        class="h-11 rounded-xl"
                                        required
                                    >
                                        <option :value="null" disabled>
                                            Escolha o seu bairro
                                        </option>
                                        <option
                                            v-for="zone in deliveryZones"
                                            :key="zone.id"
                                            :value="zone.id"
                                        >
                                            {{ zone.name }} —
                                            {{
                                                zone.fee_cents === 0
                                                    ? 'grátis'
                                                    : formatCents(
                                                          zone.fee_cents,
                                                      )
                                            }}
                                        </option>
                                    </NativeSelect>
                                    <InputError
                                        :message="errorFor('delivery_zone_id')"
                                    />
                                </div>
                                <div class="grid grid-cols-[1fr_6rem] gap-3">
                                    <div class="grid gap-2">
                                        <Label for="street">Rua</Label>
                                        <Input
                                            id="street"
                                            v-model="form.address.street"
                                            class="h-11 rounded-xl"
                                            autocomplete="address-line1"
                                            required
                                        />
                                        <InputError
                                            :message="
                                                errorFor('address.street')
                                            "
                                        />
                                    </div>
                                    <div class="grid gap-2">
                                        <Label for="number">Número</Label>
                                        <Input
                                            id="number"
                                            v-model="form.address.number"
                                            class="h-11 rounded-xl"
                                            required
                                        />
                                        <InputError
                                            :message="
                                                errorFor('address.number')
                                            "
                                        />
                                    </div>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="complement">Complemento</Label>
                                    <Input
                                        id="complement"
                                        v-model="form.address.complement"
                                        class="h-11 rounded-xl"
                                        placeholder="Apto, bloco (opcional)"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="reference"
                                        >Ponto de referência</Label
                                    >
                                    <Input
                                        id="reference"
                                        v-model="form.address.reference"
                                        class="h-11 rounded-xl"
                                        placeholder="Opcional"
                                    />
                                </div>
                            </section>

                            <section
                                v-if="slots.length > 0 || !store.isOpen"
                                class="grid gap-4 rounded-3xl bg-white p-5 shadow-soft"
                            >
                                <h3 class="font-semibold">Quando?</h3>
                                <div
                                    v-if="store.isOpen"
                                    class="grid grid-cols-2 gap-2"
                                >
                                    <button
                                        v-for="option in [
                                            {
                                                value: 'now',
                                                label: 'O quanto antes',
                                            },
                                            {
                                                value: 'scheduled',
                                                label: 'Agendar',
                                            },
                                        ]"
                                        :key="option.value"
                                        type="button"
                                        class="h-11 rounded-xl text-sm font-medium"
                                        :class="
                                            form.when === option.value
                                                ? 'bg-brand text-white'
                                                : 'bg-brand-surface text-brand-ink'
                                        "
                                        @click="form.when = option.value"
                                    >
                                        {{ option.label }}
                                    </button>
                                </div>
                                <p
                                    v-else-if="slots.length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    A loja está fechada e não há horários para
                                    agendar.
                                </p>
                                <div
                                    v-if="isScheduling && slots.length > 0"
                                    class="grid gap-2"
                                >
                                    <Label for="scheduled_for">Horário</Label>
                                    <NativeSelect
                                        id="scheduled_for"
                                        v-model="form.scheduled_for"
                                        class="h-11 rounded-xl"
                                        required
                                    >
                                        <option value="" disabled>
                                            Escolha o horário
                                        </option>
                                        <optgroup
                                            v-for="(
                                                daySlots, day
                                            ) in slotsByDay"
                                            :key="day"
                                            :label="String(day)"
                                        >
                                            <option
                                                v-for="slot in daySlots"
                                                :key="slot.value"
                                                :value="slot.value"
                                            >
                                                {{ slot.day }}, {{ slot.time }}
                                            </option>
                                        </optgroup>
                                    </NativeSelect>
                                    <InputError
                                        :message="errorFor('scheduled_for')"
                                    />
                                </div>
                            </section>

                            <section
                                v-if="onlinePaymentMethods?.length"
                                class="grid gap-3 rounded-3xl bg-white p-5 shadow-soft"
                            >
                                <h3 class="font-semibold">Pagar agora</h3>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        v-for="method in onlinePaymentMethods"
                                        :key="method.value"
                                        type="button"
                                        class="min-h-11 rounded-xl px-3 py-2 text-sm font-medium"
                                        :class="
                                            form.payment_method === method.value
                                                ? 'bg-brand text-white'
                                                : 'bg-brand-surface text-brand-ink'
                                        "
                                        :aria-pressed="
                                            form.payment_method === method.value
                                        "
                                        @click="
                                            form.payment_method = method.value
                                        "
                                    >
                                        {{ method.label }}
                                    </button>
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    O pedido vai para a loja assim que o
                                    pagamento for confirmado.
                                </p>
                            </section>

                            <section
                                class="grid gap-4 rounded-3xl bg-white p-5 shadow-soft"
                            >
                                <h3 class="font-semibold">
                                    Pagamento na
                                    {{ isDelivery ? 'entrega' : 'retirada' }}
                                </h3>
                                <div class="grid grid-cols-2 gap-2">
                                    <button
                                        v-for="method in paymentMethods"
                                        :key="method.value"
                                        type="button"
                                        class="min-h-11 rounded-xl px-3 py-2 text-sm font-medium"
                                        :class="
                                            form.payment_method === method.value
                                                ? 'bg-brand text-white'
                                                : 'bg-brand-surface text-brand-ink'
                                        "
                                        :aria-pressed="
                                            form.payment_method === method.value
                                        "
                                        @click="
                                            form.payment_method = method.value
                                        "
                                    >
                                        {{ method.label }}
                                    </button>
                                </div>
                                <InputError
                                    :message="errorFor('payment_method')"
                                />
                                <div
                                    v-if="form.payment_method === 'cash'"
                                    class="grid gap-2"
                                >
                                    <p class="text-sm font-medium">
                                        Vai precisar de troco?
                                    </p>
                                    <div class="grid grid-cols-2 gap-2">
                                        <button
                                            v-for="choice in [
                                                {
                                                    value: false,
                                                    label: 'Sim, preciso',
                                                },
                                                {
                                                    value: true,
                                                    label: 'Não preciso',
                                                },
                                            ]"
                                            :key="choice.label"
                                            type="button"
                                            class="h-11 rounded-xl text-sm font-medium transition"
                                            :class="
                                                form.no_change === choice.value
                                                    ? 'bg-brand text-white'
                                                    : 'bg-brand-surface text-brand-ink'
                                            "
                                            :aria-pressed="
                                                form.no_change === choice.value
                                            "
                                            @click="
                                                form.no_change = choice.value
                                            "
                                        >
                                            {{ choice.label }}
                                        </button>
                                    </div>
                                    <template v-if="form.no_change === false">
                                        <Label for="change"
                                            >Troco para quanto?</Label
                                        >
                                        <Input
                                            id="change"
                                            v-model="form.change_for"
                                            class="h-11 rounded-xl"
                                            inputmode="decimal"
                                            placeholder="Ex.: 100,00"
                                        />
                                    </template>
                                    <InputError
                                        :message="errorFor('change_for')"
                                    />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="order-notes"
                                        >Observações do pedido</Label
                                    >
                                    <TextareaField
                                        id="order-notes"
                                        v-model="form.notes"
                                        maxlength="500"
                                        placeholder="Opcional"
                                        class="min-h-16 rounded-xl"
                                    />
                                </div>
                            </section>

                            <section
                                class="grid gap-2 rounded-3xl bg-white p-5 shadow-soft"
                            >
                                <Label for="coupon" class="font-semibold"
                                    >Cupom de desconto</Label
                                >
                                <div
                                    v-if="coupon"
                                    class="flex items-center justify-between gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"
                                >
                                    <span
                                        ><strong class="font-mono">{{
                                            coupon.code
                                        }}</strong>
                                        · {{ coupon.label }}</span
                                    >
                                    <button
                                        type="button"
                                        class="text-xs font-medium underline"
                                        @click="removeCoupon"
                                    >
                                        Remover
                                    </button>
                                </div>
                                <div v-else class="flex gap-2">
                                    <Input
                                        id="coupon"
                                        v-model="couponInput"
                                        class="h-11 rounded-xl font-mono uppercase placeholder:font-sans placeholder:normal-case"
                                        placeholder="Tem um cupom?"
                                        @keydown.enter.prevent="applyCoupon()"
                                    />
                                    <button
                                        type="button"
                                        class="flex h-11 items-center gap-2 rounded-xl bg-brand-surface px-4 text-sm font-medium text-brand disabled:opacity-50"
                                        :disabled="
                                            checkingCoupon ||
                                            couponInput.trim() === ''
                                        "
                                        @click="applyCoupon()"
                                    >
                                        <Spinner v-if="checkingCoupon" />
                                        Aplicar
                                    </button>
                                </div>
                                <InputError
                                    :message="couponError ?? errorFor('coupon')"
                                />
                            </section>
                        </div>
                    </div>

                    <div
                        class="flex flex-col gap-3 rounded-t-[2rem] bg-white px-5 pt-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-[0_-10px_30px_-15px_rgb(30_39_86/0.25)]"
                    >
                        <dl
                            class="grid gap-1 rounded-2xl bg-brand-surface px-4 py-3 text-xs"
                        >
                            <div class="flex justify-between">
                                <dt class="text-brand">Subtotal</dt>
                                <dd class="text-brand">
                                    {{ formatCents(subtotalCents) }}
                                </dd>
                            </div>
                            <div
                                v-if="discountCents > 0"
                                class="flex justify-between text-emerald-600"
                            >
                                <dt>Desconto</dt>
                                <dd>− {{ formatCents(discountCents) }}</dd>
                            </div>
                            <div v-if="isDelivery" class="flex justify-between">
                                <dt class="text-brand">Entrega</dt>
                                <dd
                                    :class="
                                        deliveryFeeCents === 0 &&
                                        form.delivery_zone_id !== null
                                            ? 'text-brand-accent'
                                            : 'text-brand'
                                    "
                                >
                                    {{
                                        form.delivery_zone_id === null
                                            ? 'escolha o bairro'
                                            : deliveryFeeCents === 0
                                              ? 'Grátis'
                                              : formatCents(deliveryFeeCents)
                                    }}
                                </dd>
                            </div>
                            <div
                                class="mt-1 flex items-baseline justify-between"
                            >
                                <dt
                                    class="text-base font-semibold text-brand-ink"
                                >
                                    Total
                                </dt>
                                <dd class="text-lg font-bold text-brand">
                                    {{ formatCents(totalCents) }}
                                </dd>
                            </div>
                        </dl>

                        <p
                            v-if="belowMinimum"
                            class="text-center text-xs text-rose-600"
                        >
                            Pedido mínimo de
                            {{ formatCents(store.minimumOrderCents) }} (sem a
                            entrega).
                        </p>

                        <button
                            type="submit"
                            class="flex h-14 items-center justify-center gap-2 rounded-2xl bg-linear-to-r from-brand to-brand-accent text-base font-medium text-white shadow-lg shadow-brand/25 disabled:opacity-50"
                            :disabled="!canSubmit"
                        >
                            <Spinner v-if="form.processing" />
                            <template v-if="isScheduling"
                                >Agendar pedido</template
                            >
                            <template v-else-if="store.isOpen"
                                >Realizar pedido</template
                            >
                            <template v-else>Loja fechada</template>
                        </button>
                    </div>
                </form>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
