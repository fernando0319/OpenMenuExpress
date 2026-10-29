<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import CouponController from '@/actions/App/Http/Controllers/Marketing/CouponController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/marketing/coupons';

type CouponInput = {
    id: number;
    code: string;
    description: string | null;
    type: 'percent' | 'fixed' | 'free_delivery';
    percent: number | null;
    amount: string;
    max_discount: string;
    min_subtotal: string;
    usage_limit: number | null;
    per_customer_limit: number | null;
    first_order_only: boolean;
    fulfillment: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
};

const props = defineProps<{
    coupon: CouponInput | null;
    types: { value: CouponInput['type']; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cupons', href: index() }],
    },
});

const title = computed(() => (props.coupon ? 'Editar cupom' : 'Novo cupom'));

const form = useForm({
    code: props.coupon?.code ?? '',
    description: props.coupon?.description ?? '',
    type: props.coupon?.type ?? ('percent' as CouponInput['type']),
    percent: props.coupon?.percent ?? 10,
    amount: props.coupon?.amount ?? '',
    max_discount: props.coupon?.max_discount ?? '',
    min_subtotal: props.coupon?.min_subtotal ?? '',
    usage_limit: props.coupon?.usage_limit ?? null,
    per_customer_limit: props.coupon?.per_customer_limit ?? 1,
    first_order_only: props.coupon?.first_order_only ?? false,
    fulfillment: props.coupon?.fulfillment ?? '',
    starts_at: props.coupon?.starts_at ?? '',
    ends_at: props.coupon?.ends_at ?? '',
    is_active: props.coupon?.is_active ?? true,
});

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function submit(): void {
    if (props.coupon) {
        form.put(CouponController.update.url(props.coupon.id));
    } else {
        form.post(CouponController.store.url());
    }
}
</script>

<template>
    <Head :title="title" />

    <form class="flex max-w-3xl flex-col gap-8 p-4" @submit.prevent="submit">
        <Heading :title="title" />

        <section class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="code">Código</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    required
                    class="font-mono uppercase"
                    placeholder="EX.: BEMVINDO10"
                />
                <InputError :message="form.errors.code" />
            </div>
            <div class="grid gap-2">
                <Label for="description">Descrição interna</Label>
                <Input
                    id="description"
                    v-model="form.description"
                    placeholder="Opcional, só você vê"
                />
            </div>
        </section>

        <fieldset class="grid gap-3">
            <legend class="mb-2 text-sm font-medium">Tipo de desconto</legend>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="type in types"
                    :key="type.value"
                    class="flex flex-1 cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm has-[:checked]:border-primary"
                >
                    <input
                        v-model="form.type"
                        type="radio"
                        :value="type.value"
                    />
                    {{ type.label }}
                </label>
            </div>

            <div
                v-if="form.type === 'percent'"
                class="grid gap-4 sm:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label for="percent">Porcentagem</Label>
                    <Input
                        id="percent"
                        v-model.number="form.percent"
                        type="number"
                        min="1"
                        max="100"
                    />
                    <InputError :message="form.errors.percent" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_discount">Desconto máximo</Label>
                    <Input
                        id="max_discount"
                        v-model="form.max_discount"
                        inputmode="decimal"
                        placeholder="Sem limite"
                    />
                    <InputError :message="errorFor('max_discount_cents')" />
                </div>
            </div>

            <div v-else-if="form.type === 'fixed'" class="grid max-w-xs gap-2">
                <Label for="amount">Valor do desconto</Label>
                <Input
                    id="amount"
                    v-model="form.amount"
                    inputmode="decimal"
                    placeholder="R$ 0,00"
                />
                <InputError :message="errorFor('amount_cents')" />
            </div>

            <p v-else class="text-sm text-muted-foreground">
                O cliente não paga a taxa de entrega. Vale só para pedidos com
                entrega.
            </p>
        </fieldset>

        <section class="grid gap-4">
            <h3 class="text-base font-medium">Regras</h3>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="min_subtotal">Pedido mínimo</Label>
                    <Input
                        id="min_subtotal"
                        v-model="form.min_subtotal"
                        inputmode="decimal"
                        placeholder="Sem mínimo"
                    />
                    <InputError :message="errorFor('min_subtotal_cents')" />
                </div>
                <div class="grid gap-2">
                    <Label for="usage_limit">Limite total de usos</Label>
                    <Input
                        id="usage_limit"
                        v-model.number="form.usage_limit"
                        type="number"
                        min="1"
                        placeholder="Sem limite"
                    />
                    <InputError :message="form.errors.usage_limit" />
                </div>
                <div class="grid gap-2">
                    <Label for="per_customer_limit">Usos por cliente</Label>
                    <Input
                        id="per_customer_limit"
                        v-model.number="form.per_customer_limit"
                        type="number"
                        min="1"
                        placeholder="Sem limite"
                    />
                    <InputError :message="form.errors.per_customer_limit" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="fulfillment">Vale para</Label>
                    <NativeSelect id="fulfillment" v-model="form.fulfillment">
                        <option value="">Entrega e retirada</option>
                        <option value="delivery">Só entrega</option>
                        <option value="pickup">Só retirada</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-2">
                    <Label for="starts_at">Começa em</Label>
                    <Input
                        id="starts_at"
                        v-model="form.starts_at"
                        type="datetime-local"
                    />
                    <InputError :message="form.errors.starts_at" />
                </div>
                <div class="grid gap-2">
                    <Label for="ends_at">Termina em</Label>
                    <Input
                        id="ends_at"
                        v-model="form.ends_at"
                        type="datetime-local"
                    />
                    <InputError :message="form.errors.ends_at" />
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="first_order_only"
                        v-model="form.first_order_only"
                    />
                    <Label for="first_order_only"
                        >Só na primeira compra do cliente</Label
                    >
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="is_active" v-model="form.is_active" />
                    <Label for="is_active">Cupom ativo</Label>
                </div>
            </div>
        </section>

        <div class="flex gap-3">
            <Button type="submit" :disabled="form.processing">Salvar</Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancelar</Link>
            </Button>
        </div>
    </form>
</template>
