<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import OptionGroupController from '@/actions/App/Http/Controllers/Catalog/OptionGroupController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/catalog/option-groups';
import type { PricingModeOption } from '@/types';

type OptionInput = {
    id: number | null;
    name: string;
    description: string | null;
    price: string;
    is_active: boolean;
};

type GroupInput = {
    id: number;
    name: string;
    min_choices: number;
    max_choices: number;
    pricing_mode: PricingModeOption['value'];
    allows_quantity: boolean;
    is_active: boolean;
    options: OptionInput[];
};

const props = defineProps<{
    group: GroupInput | null;
    pricingModes: PricingModeOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Grupos de opções', href: index() }],
    },
});

const isEditing = computed(() => props.group !== null);
const title = computed(() =>
    isEditing.value ? 'Editar grupo de opções' : 'Novo grupo de opções',
);

const form = useForm({
    name: props.group?.name ?? '',
    min_choices: props.group?.min_choices ?? 0,
    max_choices: props.group?.max_choices ?? 1,
    pricing_mode: props.group?.pricing_mode ?? 'sum',
    allows_quantity: props.group?.allows_quantity ?? false,
    is_active: props.group?.is_active ?? true,
    options: props.group?.options.map((option) => ({ ...option })) ?? [
        emptyOption(),
    ],
});

const pricingHelp: Record<PricingModeOption['value'], string> = {
    sum: 'Cada opção escolhida soma o seu preço. Ideal para adicionais.',
    highest: 'Cobra só a opção mais cara. Comum em pizza de dois sabores.',
    average:
        'Cobra a média das opções escolhidas. Outra forma de cobrar pizza meio a meio.',
};

function emptyOption(): OptionInput {
    return {
        id: null,
        name: '',
        description: null,
        price: '',
        is_active: true,
    };
}

function addOption(): void {
    form.options.push(emptyOption());
}

function removeOption(position: number): void {
    form.options.splice(position, 1);
}

function moveOption(position: number, offset: -1 | 1): void {
    const target = position + offset;

    if (target < 0 || target >= form.options.length) {
        return;
    }

    const [option] = form.options.splice(position, 1);
    form.options.splice(target, 0, option);
}

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function submit(): void {
    if (props.group) {
        form.put(OptionGroupController.update.url(props.group.id));
    } else {
        form.post(OptionGroupController.store.url());
    }
}
</script>

<template>
    <Head :title="title" />

    <form class="flex max-w-3xl flex-col gap-8 p-4" @submit.prevent="submit">
        <Heading
            :title="title"
            description="Defina quantas opções o cliente pode escolher e como elas mudam o preço."
        />

        <section class="grid gap-6">
            <div class="grid gap-2">
                <Label for="name">Nome do grupo</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    placeholder="Ex.: Adicionais, Sabores, Tamanho"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="min_choices">Mínimo de escolhas</Label>
                    <Input
                        id="min_choices"
                        v-model.number="form.min_choices"
                        type="number"
                        min="0"
                    />
                    <p class="text-xs text-muted-foreground">
                        0 deixa o grupo opcional.
                    </p>
                    <InputError :message="form.errors.min_choices" />
                </div>
                <div class="grid gap-2">
                    <Label for="max_choices">Máximo de escolhas</Label>
                    <Input
                        id="max_choices"
                        v-model.number="form.max_choices"
                        type="number"
                        min="1"
                    />
                    <InputError :message="form.errors.max_choices" />
                </div>
            </div>

            <fieldset class="grid gap-3">
                <legend class="mb-2 text-sm font-medium">
                    Como as opções mudam o preço
                </legend>
                <label
                    v-for="mode in pricingModes"
                    :key="mode.value"
                    class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 has-[:checked]:border-primary"
                >
                    <input
                        v-model="form.pricing_mode"
                        type="radio"
                        name="pricing_mode"
                        :value="mode.value"
                        class="mt-1"
                    />
                    <span>
                        <span class="block text-sm font-medium">{{
                            mode.label
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            pricingHelp[mode.value]
                        }}</span>
                    </span>
                </label>
                <InputError :message="form.errors.pricing_mode" />
            </fieldset>

            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <Checkbox
                        id="allows_quantity"
                        v-model="form.allows_quantity"
                    />
                    <Label for="allows_quantity"
                        >Cliente pode escolher a mesma opção mais de uma vez
                        (ex.: 2× bacon)</Label
                    >
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="is_active" v-model="form.is_active" />
                    <Label for="is_active">Grupo ativo</Label>
                </div>
            </div>
        </section>

        <section class="grid gap-4">
            <div class="flex items-center justify-between">
                <h3 class="text-base font-medium">Opções</h3>
                <Button type="button" variant="outline" @click="addOption">
                    <Plus />
                    Adicionar opção
                </Button>
            </div>
            <InputError :message="form.errors.options" />

            <div
                v-for="(option, position) in form.options"
                :key="option.id ?? `new-${position}`"
                class="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_8rem_auto]"
            >
                <div class="grid gap-2">
                    <Label :for="`option-name-${position}`" class="sr-only"
                        >Nome da opção</Label
                    >
                    <Input
                        :id="`option-name-${position}`"
                        v-model="option.name"
                        placeholder="Nome (ex.: Bacon)"
                        required
                    />
                    <InputError
                        :message="errorFor(`options.${position}.name`)"
                    />
                    <Input
                        v-model="option.description"
                        placeholder="Descrição (opcional)"
                        aria-label="Descrição da opção"
                    />
                </div>
                <div class="grid content-start gap-2">
                    <Label :for="`option-price-${position}`" class="sr-only"
                        >Preço</Label
                    >
                    <Input
                        :id="`option-price-${position}`"
                        v-model="option.price"
                        inputmode="decimal"
                        placeholder="R$ 0,00"
                    />
                    <InputError
                        :message="errorFor(`options.${position}.price_cents`)"
                    />
                    <div class="flex items-center gap-2">
                        <Checkbox
                            :id="`option-active-${position}`"
                            v-model="option.is_active"
                        />
                        <Label
                            :for="`option-active-${position}`"
                            class="text-xs"
                            >Disponível</Label
                        >
                    </div>
                </div>
                <div class="flex items-start gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Mover para cima"
                        :disabled="position === 0"
                        @click="moveOption(position, -1)"
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Mover para baixo"
                        :disabled="position === form.options.length - 1"
                        @click="moveOption(position, 1)"
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Remover opção"
                        :disabled="form.options.length === 1"
                        @click="removeOption(position)"
                    >
                        <Trash2 />
                    </Button>
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
