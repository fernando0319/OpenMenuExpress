<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp } from '@lucide/vue';
import PaymentsController from '@/actions/App/Http/Controllers/StoreSettings/PaymentsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/store-settings/payments';

type MethodRow = { value: string; label: string; active: boolean };

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Formas de pagamento', href: edit() }],
    },
});

const props = defineProps<{
    methods: MethodRow[];
}>();

const form = useForm({
    methods: props.methods.map((method) => ({ ...method })),
});

function move(index: number, delta: -1 | 1): void {
    const target = index + delta;

    if (target < 0 || target >= form.methods.length) {
        return;
    }

    const rows = [...form.methods];
    [rows[index], rows[target]] = [rows[target], rows[index]];
    form.methods = rows;
}

function save(): void {
    form.put(PaymentsController.update.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Formas de pagamento" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Formas de pagamento"
            description="O que a loja aceita. Vale para o cardápio, o PDV e as mesas, nesta ordem."
        />

        <form class="flex flex-col gap-4" @submit.prevent="save">
            <ul class="flex flex-col divide-y rounded-xl border">
                <li
                    v-for="(method, index) in form.methods"
                    :key="method.value"
                    class="flex items-center gap-3 p-3"
                >
                    <Label class="flex flex-1 items-center gap-3 font-normal">
                        <Checkbox v-model="method.active" />
                        {{ method.label }}
                    </Label>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="index === 0"
                        :aria-label="`Subir ${method.label}`"
                        @click="move(index, -1)"
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="index === form.methods.length - 1"
                        :aria-label="`Descer ${method.label}`"
                        @click="move(index, 1)"
                    >
                        <ArrowDown />
                    </Button>
                </li>
            </ul>
            <InputError :message="form.errors.methods" />

            <Button type="submit" class="self-start" :disabled="form.processing"
                >Salvar</Button
            >
        </form>

        <p
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
        >
            Por enquanto o pagamento é feito na entrega, na retirada ou no
            balcão. Pagamentos online (gateways como Mercado Pago, Nu Pay, Apple
            Pay e Bitcoin) vão aparecer nesta lista como módulos.
        </p>
    </div>
</template>
