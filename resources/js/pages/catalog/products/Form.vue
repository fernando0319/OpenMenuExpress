<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ProductController from '@/actions/App/Http/Controllers/Catalog/ProductController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create as createOptionGroup } from '@/routes/catalog/option-groups';
import { index as pizzasIndex } from '@/routes/catalog/pizzas';
import { index } from '@/routes/catalog/products';
import type { OptionGroupChoice, SelectOption } from '@/types';

type AttachedGroup = {
    id: number;
    min_choices: number | null;
    max_choices: number | null;
};

type ProductInput = {
    id: number;
    category_id: number;
    kind: 'standard' | 'pizza';
    pizza_max_flavors: number | null;
    pizza_slices: number | null;
    pizza_diameter_cm: number | null;
    name: string;
    description: string | null;
    sku: string | null;
    imageUrl: string | null;
    price: string;
    promotional_price: string;
    is_featured: boolean;
    is_active: boolean;
    track_stock: boolean;
    stock_quantity: number | null;
    option_groups: AttachedGroup[];
};

const props = defineProps<{
    product: ProductInput | null;
    presetKind: 'standard' | 'pizza';
    categories: SelectOption[];
    optionGroups: OptionGroupChoice[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Produtos', href: index() }],
    },
});

const title = computed(() =>
    props.product ? 'Editar produto' : 'Novo produto',
);

const form = useForm({
    category_id: props.product?.category_id ?? props.categories[0]?.id ?? null,
    kind: props.product?.kind ?? props.presetKind,
    pizza_max_flavors: props.product?.pizza_max_flavors ?? 2,
    pizza_slices: props.product?.pizza_slices ?? (null as number | null),
    pizza_diameter_cm:
        props.product?.pizza_diameter_cm ?? (null as number | null),
    name: props.product?.name ?? '',
    description: props.product?.description ?? '',
    sku: props.product?.sku ?? '',
    price: props.product?.price ?? '',
    promotional_price: props.product?.promotional_price ?? '',
    is_featured: props.product?.is_featured ?? false,
    is_active: props.product?.is_active ?? true,
    track_stock: props.product?.track_stock ?? false,
    stock_quantity: props.product?.stock_quantity ?? null,
    option_groups:
        props.product?.option_groups.map((group) => ({ ...group })) ?? [],
    image: null as File | null,
    remove_image: false,
});

const imagePreview = ref<string | null>(props.product?.imageUrl ?? null);

function pickImage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    form.image = file;
    form.remove_image = false;
    imagePreview.value = file
        ? URL.createObjectURL(file)
        : (props.product?.imageUrl ?? null);
}

function removeImage(): void {
    form.image = null;
    form.remove_image = true;
    imagePreview.value = null;
}

const groupToAdd = ref<number | null>(null);

const groupsById = computed(
    () => new Map(props.optionGroups.map((group) => [group.id, group])),
);

const availableGroups = computed(() =>
    props.optionGroups.filter(
        (group) =>
            !form.option_groups.some((attached) => attached.id === group.id),
    ),
);

function addGroup(): void {
    if (groupToAdd.value === null) {
        return;
    }

    form.option_groups.push({
        id: groupToAdd.value,
        min_choices: null,
        max_choices: null,
    });
    groupToAdd.value = null;
}

function removeGroup(position: number): void {
    form.option_groups.splice(position, 1);
}

function moveGroup(position: number, offset: -1 | 1): void {
    const target = position + offset;

    if (target < 0 || target >= form.option_groups.length) {
        return;
    }

    const [group] = form.option_groups.splice(position, 1);
    form.option_groups.splice(target, 0, group);
}

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function submit(): void {
    // Multipart so the photo can travel with the form; Laravel reads _method as PUT.
    const options = { forceFormData: true };

    if (props.product) {
        form.transform((data) => ({ ...data, _method: 'put' })).post(
            ProductController.update.url(props.product.id),
            options,
        );
    } else {
        form.transform((data) => data).post(
            ProductController.store.url(),
            options,
        );
    }
}
</script>

<template>
    <Head :title="title" />

    <form class="flex max-w-3xl flex-col gap-8 p-4" @submit.prevent="submit">
        <Heading :title="title" />

        <section class="grid gap-6">
            <div class="grid gap-2">
                <Label>Tipo</Label>
                <div
                    class="flex max-w-md gap-1 rounded-xl bg-muted p-1"
                    role="radiogroup"
                    aria-label="Tipo do produto"
                >
                    <button
                        v-for="option in [
                            { value: 'standard', label: 'Produto comum' },
                            { value: 'pizza', label: 'Pizza (tamanho)' },
                        ] as const"
                        :key="option.value"
                        type="button"
                        role="radio"
                        :aria-checked="form.kind === option.value"
                        class="flex-1 rounded-lg px-3 py-1.5 text-sm font-medium transition"
                        :class="
                            form.kind === option.value
                                ? 'bg-background shadow-xs'
                                : 'text-muted-foreground'
                        "
                        @click="form.kind = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <p
                    v-if="form.kind === 'pizza'"
                    class="text-sm text-muted-foreground"
                >
                    Cada tamanho (Broto, Média, Grande...) é um produto. O preço
                    vem da tabela de sabores e bordas em
                    <Link :href="pizzasIndex()" class="text-primary underline"
                        >Pizzas</Link
                    >; o cliente vê "a partir de" o sabor mais barato.
                </p>
            </div>

            <div
                v-if="form.kind === 'pizza'"
                class="grid gap-4 rounded-xl border p-4 sm:grid-cols-3"
            >
                <div class="grid gap-2">
                    <Label for="pizza_max_flavors">Máximo de sabores</Label>
                    <Input
                        id="pizza_max_flavors"
                        v-model.number="form.pizza_max_flavors"
                        type="number"
                        min="1"
                        max="8"
                    />
                    <InputError :message="errorFor('pizza_max_flavors')" />
                </div>
                <div class="grid gap-2">
                    <Label for="pizza_slices">Fatias (opcional)</Label>
                    <Input
                        id="pizza_slices"
                        v-model.number="form.pizza_slices"
                        type="number"
                        min="1"
                    />
                    <InputError :message="errorFor('pizza_slices')" />
                </div>
                <div class="grid gap-2">
                    <Label for="pizza_diameter_cm"
                        >Tamanho em cm (opcional)</Label
                    >
                    <Input
                        id="pizza_diameter_cm"
                        v-model.number="form.pizza_diameter_cm"
                        type="number"
                        min="10"
                    />
                    <InputError :message="errorFor('pizza_diameter_cm')" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Nome</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        required
                        placeholder="Ex.: X-Burger"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="category_id">Categoria</Label>
                    <NativeSelect
                        id="category_id"
                        v-model="form.category_id"
                        required
                    >
                        <option
                            v-for="category in categories"
                            :key="category.id"
                            :value="category.id"
                        >
                            {{ category.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.category_id" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">Descrição</Label>
                <TextareaField
                    id="description"
                    v-model="form.description"
                    placeholder="Ingredientes, tamanho, observações"
                />
                <InputError :message="form.errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="image">Foto</Label>
                <div class="flex items-center gap-4">
                    <img
                        v-if="imagePreview"
                        :src="imagePreview"
                        alt="Foto do produto"
                        class="size-24 rounded-lg border object-cover"
                    />
                    <div class="flex flex-col gap-2">
                        <input
                            id="image"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="text-sm"
                            @change="pickImage"
                        />
                        <Button
                            v-if="imagePreview"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="w-fit"
                            @click="removeImage"
                        >
                            Remover foto
                        </Button>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    JPG, PNG ou WebP até 4 MB. Fotos quadradas ficam melhores.
                </p>
                <InputError :message="form.errors.image" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div v-if="form.kind !== 'pizza'" class="grid gap-2">
                    <Label for="price">Preço</Label>
                    <Input
                        id="price"
                        v-model="form.price"
                        required
                        inputmode="decimal"
                        placeholder="R$ 0,00"
                    />
                    <InputError :message="errorFor('price_cents')" />
                </div>
                <div v-if="form.kind !== 'pizza'" class="grid gap-2">
                    <Label for="promotional_price">Preço promocional</Label>
                    <Input
                        id="promotional_price"
                        v-model="form.promotional_price"
                        inputmode="decimal"
                        placeholder="Opcional"
                    />
                    <InputError
                        :message="errorFor('promotional_price_cents')"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="sku">Código</Label>
                    <Input id="sku" v-model="form.sku" placeholder="Opcional" />
                    <InputError :message="form.errors.sku" />
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <Checkbox id="is_active" v-model="form.is_active" />
                    <Label for="is_active">Disponível no cardápio</Label>
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="is_featured" v-model="form.is_featured" />
                    <Label for="is_featured"
                        >Destacar no topo do cardápio</Label
                    >
                </div>
                <div class="flex items-center gap-2">
                    <Checkbox id="track_stock" v-model="form.track_stock" />
                    <Label for="track_stock">Controlar estoque</Label>
                </div>
                <div v-if="form.track_stock" class="grid max-w-48 gap-2">
                    <Label for="stock_quantity">Estoque atual</Label>
                    <Input
                        id="stock_quantity"
                        v-model.number="form.stock_quantity"
                        type="number"
                    />
                    <InputError :message="form.errors.stock_quantity" />
                </div>
            </div>
        </section>

        <section class="grid gap-4">
            <div>
                <h3 class="text-base font-medium">
                    {{
                        form.kind === 'pizza'
                            ? 'Adicionais (opcional)'
                            : 'Grupos de opções'
                    }}
                </h3>
                <p class="text-sm text-muted-foreground">
                    Adicionais, sabores e escolhas que o cliente faz ao pedir
                    este produto. Os limites em branco usam os do grupo.
                </p>
            </div>

            <div
                v-for="(attached, position) in form.option_groups"
                :key="attached.id"
                class="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_6rem_6rem_auto] sm:items-end"
            >
                <div>
                    <p class="text-sm font-medium">
                        {{ groupsById.get(attached.id)?.name }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Padrão do grupo:
                        {{ groupsById.get(attached.id)?.min_choices }} a
                        {{ groupsById.get(attached.id)?.max_choices }} escolhas
                    </p>
                    <InputError
                        :message="errorFor(`option_groups.${position}.id`)"
                    />
                </div>
                <div class="grid gap-1">
                    <Label :for="`group-min-${position}`" class="text-xs"
                        >Mínimo</Label
                    >
                    <Input
                        :id="`group-min-${position}`"
                        v-model.number="attached.min_choices"
                        type="number"
                        min="0"
                    />
                </div>
                <div class="grid gap-1">
                    <Label :for="`group-max-${position}`" class="text-xs"
                        >Máximo</Label
                    >
                    <Input
                        :id="`group-max-${position}`"
                        v-model.number="attached.max_choices"
                        type="number"
                        min="1"
                    />
                </div>
                <div class="flex gap-1">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Mover para cima"
                        :disabled="position === 0"
                        @click="moveGroup(position, -1)"
                    >
                        <ArrowUp />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Mover para baixo"
                        :disabled="position === form.option_groups.length - 1"
                        @click="moveGroup(position, 1)"
                    >
                        <ArrowDown />
                    </Button>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        aria-label="Remover grupo do produto"
                        @click="removeGroup(position)"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </div>

            <div v-if="availableGroups.length > 0" class="flex gap-2">
                <NativeSelect
                    v-model="groupToAdd"
                    aria-label="Grupo de opções para adicionar"
                    class="max-w-xs"
                >
                    <option :value="null">Escolha um grupo…</option>
                    <option
                        v-for="group in availableGroups"
                        :key="group.id"
                        :value="group.id"
                    >
                        {{ group.name }} ({{ group.options_count }} opções)
                    </option>
                </NativeSelect>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="groupToAdd === null"
                    @click="addGroup"
                >
                    <Plus />
                    Adicionar
                </Button>
            </div>
            <p
                v-else-if="optionGroups.length === 0"
                class="text-sm text-muted-foreground"
            >
                Nenhum grupo cadastrado.
                <Link :href="createOptionGroup()" class="underline"
                    >Criar grupo de opções</Link
                >
            </p>
        </section>

        <div class="flex gap-3">
            <Button type="submit" :disabled="form.processing">Salvar</Button>
            <Button variant="outline" as-child>
                <Link :href="index()">Cancelar</Link>
            </Button>
        </div>
    </form>
</template>
