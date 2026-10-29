<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pause, Pencil, Percent, Pizza, Play, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PizzaController from '@/actions/App/Http/Controllers/Catalog/PizzaController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
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
import { index } from '@/routes/catalog/pizzas';
import {
    create as createProduct,
    edit as editProduct,
} from '@/routes/catalog/products';

type Size = {
    id: number;
    name: string;
    maxFlavors: number;
    slices: number | null;
    diameterCm: number | null;
    isActive: boolean;
};

type Flavor = {
    id: number;
    name: string;
    description: string | null;
    imageUrl: string | null;
    extra: string;
    unavailable: number[];
    isActive: boolean;
};

type Group = {
    id: number;
    name: string;
    kind: 'savory' | 'sweet';
    isActive: boolean;
    prices: Record<string, string>;
    flavors: Flavor[];
};

type Crust = {
    id: number;
    name: string;
    description: string | null;
    isActive: boolean;
    prices: Record<string, string>;
};

const props = defineProps<{
    settings: { pricing: 'highest' | 'average'; mixKinds: boolean };
    pricingOptions: { value: string; label: string }[];
    kinds: { value: string; label: string }[];
    sizes: Size[];
    groups: Group[];
    crusts: Crust[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Pizzas', href: index() }],
    },
});

const kindLabel = (kind: string) =>
    props.kinds.find((item) => item.value === kind)?.label ?? kind;

// Price table: one input per group (and crust) per size. Empty = not sold in that size.
const prices = useForm({
    groups: {} as Record<string, Record<string, string>>,
    crusts: {} as Record<string, Record<string, string>>,
});

function syncPriceRows(): void {
    for (const group of props.groups) {
        prices.groups[group.id] = {
            ...group.prices,
            ...(prices.isDirty ? prices.groups[group.id] : {}),
        };
    }

    for (const crust of props.crusts) {
        prices.crusts[crust.id] = {
            ...crust.prices,
            ...(prices.isDirty ? prices.crusts[crust.id] : {}),
        };
    }
}

syncPriceRows();
prices.defaults();
watch(() => [props.groups, props.crusts], syncPriceRows);

const priceErrors = computed(() => Object.values(prices.errors));

function savePrices(): void {
    prices.put(PizzaController.prices.url(), {
        preserveScroll: true,
        onSuccess: () => prices.defaults(),
    });
}

// Price adjustment by percentage.
const adjustOpen = ref(false);
const adjust = useForm({ percent: '', target: 'all' });

function submitAdjust(): void {
    adjust
        .transform((data) => ({
            ...data,
            percent: data.percent.replace(',', '.'),
        }))
        .post(PizzaController.adjust.url(), {
            preserveScroll: true,
            onSuccess: () => {
                adjustOpen.value = false;
                adjust.reset();
                prices.defaults();
            },
        });
}

// Flavor groups.
const groupOpen = ref(false);
const editingGroup = ref<Group | null>(null);
const groupForm = useForm({ name: '', kind: 'savory', is_active: true });

function startGroup(group: Group | null): void {
    editingGroup.value = group;
    groupForm.clearErrors();
    groupForm.name = group?.name ?? '';
    groupForm.kind = group?.kind ?? 'savory';
    groupForm.is_active = group?.isActive ?? true;
    groupOpen.value = true;
}

function saveGroup(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (groupOpen.value = false),
    };

    if (editingGroup.value) {
        groupForm.put(
            PizzaController.updateGroup.url(editingGroup.value.id),
            options,
        );
    } else {
        groupForm.post(PizzaController.storeGroup.url(), options);
    }
}

function destroyGroup(group: Group): void {
    if (
        confirm(
            `Excluir "${group.name}" e os ${group.flavors.length} sabor(es) dela? Pedidos antigos não mudam.`,
        )
    ) {
        router.delete(PizzaController.destroyGroup.url(group.id), {
            preserveScroll: true,
        });
    }
}

// Flavors.
const flavorGroupId = ref<number | null>(props.groups[0]?.id ?? null);
watch(
    () => props.groups.map((group) => group.id),
    (ids) => {
        if (
            flavorGroupId.value === null ||
            !ids.includes(flavorGroupId.value)
        ) {
            flavorGroupId.value = ids[0] ?? null;
        }
    },
);
const currentGroup = computed(
    () =>
        props.groups.find((group) => group.id === flavorGroupId.value) ?? null,
);

const flavorOpen = ref(false);
const editingFlavor = ref<Flavor | null>(null);
const flavorForm = useForm({
    group_id: null as number | null,
    name: '',
    description: '',
    extra: '',
    unavailable: [] as number[],
    is_active: true,
    image: null as File | null,
    remove_image: false,
});
const flavorPreview = ref<string | null>(null);

function startFlavor(flavor: Flavor | null): void {
    editingFlavor.value = flavor;
    flavorForm.clearErrors();
    flavorForm.group_id = flavorGroupId.value;
    flavorForm.name = flavor?.name ?? '';
    flavorForm.description = flavor?.description ?? '';
    flavorForm.extra = flavor?.extra ?? '';
    flavorForm.unavailable = [...(flavor?.unavailable ?? [])];
    flavorForm.is_active = flavor?.isActive ?? true;
    flavorForm.image = null;
    flavorForm.remove_image = false;
    flavorPreview.value = flavor?.imageUrl ?? null;
    flavorOpen.value = true;
}

function setAvailable(
    sizeId: number,
    available: boolean | 'indeterminate',
): void {
    flavorForm.unavailable =
        available === true
            ? flavorForm.unavailable.filter((id) => id !== sizeId)
            : [...new Set([...flavorForm.unavailable, sizeId])];
}

function pickFlavorImage(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    flavorForm.image = file;
    flavorForm.remove_image = false;
    flavorPreview.value = file
        ? URL.createObjectURL(file)
        : (editingFlavor.value?.imageUrl ?? null);
}

function saveFlavor(): void {
    const options = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (flavorOpen.value = false),
    };

    if (editingFlavor.value) {
        const id = editingFlavor.value.id;
        flavorForm
            .transform((data) => ({
                ...data,
                is_active: data.is_active ? 1 : 0,
                remove_image: data.remove_image ? 1 : 0,
                _method: 'put',
            }))
            .post(PizzaController.updateFlavor.url(id), options);
    } else {
        flavorForm
            .transform((data) => ({
                ...data,
                is_active: data.is_active ? 1 : 0,
                remove_image: 0,
            }))
            .post(PizzaController.storeFlavor.url(), options);
    }
}

function toggleFlavor(flavor: Flavor): void {
    router.patch(
        PizzaController.toggleFlavor.url(flavor.id),
        {},
        { preserveScroll: true },
    );
}

function destroyFlavor(flavor: Flavor): void {
    if (confirm(`Excluir o sabor ${flavor.name}?`)) {
        router.delete(PizzaController.destroyFlavor.url(flavor.id), {
            preserveScroll: true,
        });
    }
}

function availabilityLabel(flavor: Flavor): string {
    const missing = props.sizes.filter((size) =>
        flavor.unavailable.includes(size.id),
    );

    return missing.length === 0
        ? ''
        : `Sem ${missing.map((size) => size.name).join(', ')}`;
}

// Crusts.
const crustOpen = ref(false);
const editingCrust = ref<Crust | null>(null);
const crustForm = useForm({ name: '', description: '', is_active: true });

function startCrust(crust: Crust | null): void {
    editingCrust.value = crust;
    crustForm.clearErrors();
    crustForm.name = crust?.name ?? '';
    crustForm.description = crust?.description ?? '';
    crustForm.is_active = crust?.isActive ?? true;
    crustOpen.value = true;
}

function saveCrust(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => (crustOpen.value = false),
    };

    if (editingCrust.value) {
        crustForm.put(
            PizzaController.updateCrust.url(editingCrust.value.id),
            options,
        );
    } else {
        crustForm.post(PizzaController.storeCrust.url(), options);
    }
}

function destroyCrust(crust: Crust): void {
    if (confirm(`Excluir a borda ${crust.name}?`)) {
        router.delete(PizzaController.destroyCrust.url(crust.id), {
            preserveScroll: true,
        });
    }
}

// Rules.
const settings = useForm({
    pricing: props.settings.pricing,
    mix_kinds: props.settings.mixKinds,
});

function saveSettings(): void {
    settings.put(PizzaController.settings.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Pizzas" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Pizzas"
                description="Tamanhos, categorias de sabores com preço por tamanho, sabores e bordas. O cliente monta a pizza no cardápio, no PDV ou com o garçom."
            />
            <Button as-child variant="outline">
                <Link :href="createProduct({ query: { kind: 'pizza' } })"
                    ><Plus /> Novo tamanho</Link
                >
            </Button>
        </div>

        <div
            v-if="sizes.length === 0"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed p-10 text-center"
        >
            <Pizza class="size-10 text-muted-foreground" />
            <p class="font-medium">Comece pelos tamanhos</p>
            <p class="max-w-md text-sm text-muted-foreground">
                Cada tamanho (Broto, Média, Grande...) é um produto do tipo
                Pizza, com o número de sabores, fatias e centímetros. Depois
                volte aqui para montar a tabela de preços.
            </p>
            <Button as-child>
                <Link :href="createProduct({ query: { kind: 'pizza' } })"
                    ><Plus /> Criar o primeiro tamanho</Link
                >
            </Button>
        </div>

        <template v-else>
            <section
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Tabela de preços</h2>
                        <p class="text-sm text-muted-foreground">
                            O preço é da categoria de sabores em cada tamanho.
                            Deixe em branco o que não é vendido naquele tamanho.
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="startGroup(null)"
                            ><Plus /> Categoria</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="startCrust(null)"
                            ><Plus /> Borda</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            @click="adjustOpen = true"
                            ><Percent /> Reajustar</Button
                        >
                    </div>
                </div>

                <div class="overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/50 text-left text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-medium">Sabores</th>
                                <th
                                    v-for="size in sizes"
                                    :key="size.id"
                                    class="min-w-28 px-3 py-2 font-medium"
                                >
                                    <Link
                                        :href="editProduct(size.id)"
                                        class="hover:underline"
                                        >{{ size.name }}</Link
                                    >
                                    <span class="block text-xs font-normal">
                                        {{ size.maxFlavors }} sabor(es)<template
                                            v-if="size.slices"
                                        >
                                            · {{ size.slices }} fatias</template
                                        ><template v-if="size.diameterCm">
                                            · {{ size.diameterCm }} cm</template
                                        >
                                    </span>
                                </th>
                                <th class="px-3 py-2">
                                    <span class="sr-only">Ações</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="groups.length === 0">
                                <td
                                    :colspan="sizes.length + 2"
                                    class="px-3 py-6 text-center text-muted-foreground"
                                >
                                    Crie as categorias de sabores (ex.: Salgadas
                                    Tradicionais, Salgadas Premium, Doces).
                                </td>
                            </tr>
                            <tr
                                v-for="group in groups"
                                :key="group.id"
                                class="border-t"
                            >
                                <td class="px-3 py-2">
                                    <span
                                        class="font-medium"
                                        :class="{
                                            'text-muted-foreground line-through':
                                                !group.isActive,
                                        }"
                                        >{{ group.name }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ kindLabel(group.kind) }} ·
                                        {{ group.flavors.length }}
                                        sabor(es)</span
                                    >
                                </td>
                                <td
                                    v-for="size in sizes"
                                    :key="size.id"
                                    class="px-3 py-2"
                                >
                                    <Input
                                        v-if="prices.groups[group.id]"
                                        v-model="
                                            prices.groups[group.id][size.id]
                                        "
                                        inputmode="decimal"
                                        class="h-8 w-24"
                                        placeholder="—"
                                        :aria-label="`${group.name}, ${size.name}`"
                                    />
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Editar ${group.name}`"
                                        @click="startGroup(group)"
                                        ><Pencil
                                    /></Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Excluir ${group.name}`"
                                        @click="destroyGroup(group)"
                                        ><Trash2
                                    /></Button>
                                </td>
                            </tr>
                            <tr
                                v-if="crusts.length > 0"
                                class="border-t bg-muted/30"
                            >
                                <th
                                    :colspan="sizes.length + 2"
                                    class="px-3 py-2 text-left font-medium text-muted-foreground"
                                >
                                    Bordas recheadas
                                </th>
                            </tr>
                            <tr
                                v-for="crust in crusts"
                                :key="`crust-${crust.id}`"
                                class="border-t"
                            >
                                <td class="px-3 py-2">
                                    <span
                                        class="font-medium"
                                        :class="{
                                            'text-muted-foreground line-through':
                                                !crust.isActive,
                                        }"
                                        >{{ crust.name }}</span
                                    >
                                    <span
                                        v-if="crust.description"
                                        class="block text-xs text-muted-foreground"
                                        >{{ crust.description }}</span
                                    >
                                </td>
                                <td
                                    v-for="size in sizes"
                                    :key="size.id"
                                    class="px-3 py-2"
                                >
                                    <Input
                                        v-if="prices.crusts[crust.id]"
                                        v-model="
                                            prices.crusts[crust.id][size.id]
                                        "
                                        inputmode="decimal"
                                        class="h-8 w-24"
                                        placeholder="—"
                                        :aria-label="`Borda ${crust.name}, ${size.name}`"
                                    />
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Editar ${crust.name}`"
                                        @click="startCrust(crust)"
                                        ><Pencil
                                    /></Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :aria-label="`Excluir ${crust.name}`"
                                        @click="destroyCrust(crust)"
                                        ><Trash2
                                    /></Button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <InputError
                    v-for="message in priceErrors"
                    :key="message"
                    :message="message"
                />
                <div class="flex items-center gap-3">
                    <Button
                        :disabled="prices.processing || !prices.isDirty"
                        @click="savePrices"
                        >Salvar tabela</Button
                    >
                    <span
                        v-if="prices.isDirty"
                        class="text-sm text-muted-foreground"
                        >Há preços não salvos.</span
                    >
                </div>
            </section>

            <section
                class="flex flex-col gap-4 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Sabores</h2>
                        <p class="text-sm text-muted-foreground">
                            Cada sabor herda o preço da categoria. "Pausar" tira
                            o sabor do cardápio em todos os tamanhos (acabou o
                            ingrediente).
                        </p>
                    </div>
                    <Button
                        size="sm"
                        :disabled="!currentGroup"
                        @click="startFlavor(null)"
                        ><Plus /> Novo sabor</Button
                    >
                </div>

                <p
                    v-if="groups.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Crie uma categoria na tabela de preços para cadastrar os
                    sabores.
                </p>
                <template v-else>
                    <div
                        class="flex flex-wrap gap-2"
                        role="tablist"
                        aria-label="Categorias"
                    >
                        <button
                            v-for="group in groups"
                            :key="group.id"
                            type="button"
                            role="tab"
                            :aria-selected="group.id === flavorGroupId"
                            class="rounded-full border px-3 py-1.5 text-sm transition-colors"
                            :class="
                                group.id === flavorGroupId
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-background'
                            "
                            @click="flavorGroupId = group.id"
                        >
                            {{ group.name }}
                            <span class="opacity-75"
                                >({{ group.flavors.length }})</span
                            >
                        </button>
                    </div>

                    <p
                        v-if="currentGroup && currentGroup.flavors.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        Nenhum sabor em {{ currentGroup.name }} ainda.
                    </p>
                    <ul
                        v-else-if="currentGroup"
                        class="flex flex-col divide-y rounded-xl border"
                    >
                        <li
                            v-for="flavor in currentGroup.flavors"
                            :key="flavor.id"
                            class="flex items-center gap-3 p-3"
                        >
                            <img
                                v-if="flavor.imageUrl"
                                :src="flavor.imageUrl"
                                :alt="flavor.name"
                                class="size-12 shrink-0 rounded-lg object-cover"
                            />
                            <div
                                class="min-w-0 flex-1"
                                :class="{ 'opacity-60': !flavor.isActive }"
                            >
                                <p
                                    class="flex flex-wrap items-center gap-2 font-medium"
                                >
                                    {{ flavor.name }}
                                    <Badge
                                        v-if="!flavor.isActive"
                                        variant="outline"
                                        >Pausado</Badge
                                    >
                                    <Badge
                                        v-if="flavor.extra"
                                        variant="secondary"
                                        >+ R$ {{ flavor.extra }}</Badge
                                    >
                                </p>
                                <p
                                    v-if="flavor.description"
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    {{ flavor.description }}
                                </p>
                                <p
                                    v-if="availabilityLabel(flavor)"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ availabilityLabel(flavor) }}
                                </p>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="toggleFlavor(flavor)"
                            >
                                <template v-if="flavor.isActive"
                                    ><Pause /> Pausar</template
                                >
                                <template v-else><Play /> Voltar</template>
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Editar ${flavor.name}`"
                                @click="startFlavor(flavor)"
                                ><Pencil
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Excluir ${flavor.name}`"
                                @click="destroyFlavor(flavor)"
                                ><Trash2
                            /></Button>
                        </li>
                    </ul>
                </template>
            </section>

            <section
                class="grid gap-4 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <h2 class="font-semibold">Regras</h2>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Pizza com mais de um sabor
                    </legend>
                    <label
                        v-for="option in pricingOptions"
                        :key="option.value"
                        class="flex items-center gap-2 text-sm"
                    >
                        <input
                            v-model="settings.pricing"
                            type="radio"
                            :value="option.value"
                            class="size-4 accent-primary"
                        />
                        {{ option.label }}
                    </label>
                    <p class="text-xs text-muted-foreground">
                        Ex.: ½ Tradicional (R$ 80) + ½ Premium (R$ 90): sabor
                        mais caro = R$ 90; média = R$ 85.
                    </p>
                </fieldset>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox v-model="settings.mix_kinds" />
                    Permitir misturar sabores doces e salgados na mesma pizza
                </Label>
                <div>
                    <Button
                        :disabled="settings.processing || !settings.isDirty"
                        @click="saveSettings"
                        >Salvar regras</Button
                    >
                </div>
            </section>
        </template>
    </div>

    <Dialog v-model:open="groupOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editingGroup
                        ? 'Editar categoria'
                        : 'Nova categoria de sabores'
                }}</DialogTitle>
                <DialogDescription
                    >Os preços por tamanho ficam na tabela.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveGroup">
                <div class="grid gap-2">
                    <Label for="group-name">Nome</Label>
                    <Input
                        id="group-name"
                        v-model="groupForm.name"
                        maxlength="60"
                        placeholder="Ex.: Salgadas Premium"
                    />
                    <InputError :message="groupForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="group-kind">Tipo</Label>
                    <NativeSelect id="group-kind" v-model="groupForm.kind">
                        <option
                            v-for="kind in kinds"
                            :key="kind.value"
                            :value="kind.value"
                        >
                            {{ kind.label }}
                        </option>
                    </NativeSelect>
                </div>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox v-model="groupForm.is_active" /> Ativa no cardápio
                </Label>
                <DialogFooter>
                    <Button type="submit" :disabled="groupForm.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="flavorOpen">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editingFlavor ? 'Editar sabor' : 'Novo sabor'
                }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveFlavor">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="flavor-name">Nome</Label>
                        <Input
                            id="flavor-name"
                            v-model="flavorForm.name"
                            maxlength="80"
                            placeholder="Ex.: Calabresa"
                        />
                        <InputError :message="flavorForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="flavor-group">Categoria</Label>
                        <NativeSelect
                            id="flavor-group"
                            v-model="flavorForm.group_id"
                        >
                            <option
                                v-for="group in groups"
                                :key="group.id"
                                :value="group.id"
                            >
                                {{ group.name }}
                            </option>
                        </NativeSelect>
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="flavor-description"
                        >Ingredientes / descrição</Label
                    >
                    <Input
                        id="flavor-description"
                        v-model="flavorForm.description"
                        maxlength="300"
                        placeholder="Ex.: Molho, mussarela, calabresa e cebola"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="flavor-extra"
                        >Acréscimo sobre a categoria (opcional)</Label
                    >
                    <Input
                        id="flavor-extra"
                        v-model="flavorForm.extra"
                        inputmode="decimal"
                        placeholder="Ex.: 10,00"
                        class="max-w-40"
                    />
                    <p class="text-xs text-muted-foreground">
                        Para um sabor mais caro que os outros da categoria, sem
                        criar uma categoria nova.
                    </p>
                    <InputError :message="flavorForm.errors.extra" />
                </div>
                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Disponível nos tamanhos
                    </legend>
                    <div class="flex flex-wrap gap-x-5 gap-y-2">
                        <Label
                            v-for="size in sizes"
                            :key="size.id"
                            class="flex items-center gap-2 font-normal"
                        >
                            <Checkbox
                                :model-value="
                                    !flavorForm.unavailable.includes(size.id)
                                "
                                @update:model-value="
                                    setAvailable(size.id, $event)
                                "
                            />
                            {{ size.name }}
                        </Label>
                    </div>
                </fieldset>
                <div class="grid gap-2">
                    <Label for="flavor-image">Foto (opcional)</Label>
                    <div class="flex items-center gap-3">
                        <img
                            v-if="flavorPreview"
                            :src="flavorPreview"
                            alt=""
                            class="size-14 rounded-lg border object-cover"
                        />
                        <input
                            id="flavor-image"
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            class="text-sm"
                            @change="pickFlavorImage"
                        />
                        <Button
                            v-if="flavorPreview"
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="
                                flavorForm.image = null;
                                flavorForm.remove_image = true;
                                flavorPreview = null;
                            "
                            >Remover</Button
                        >
                    </div>
                    <InputError :message="flavorForm.errors.image" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="flavorForm.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="crustOpen">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    editingCrust ? 'Editar borda' : 'Nova borda'
                }}</DialogTitle>
                <DialogDescription
                    >Os preços por tamanho ficam na tabela.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="saveCrust">
                <div class="grid gap-2">
                    <Label for="crust-name">Nome</Label>
                    <Input
                        id="crust-name"
                        v-model="crustForm.name"
                        maxlength="60"
                        placeholder="Ex.: Catupiry"
                    />
                    <InputError :message="crustForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="crust-description">Descrição (opcional)</Label>
                    <Input
                        id="crust-description"
                        v-model="crustForm.description"
                        maxlength="200"
                    />
                </div>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox v-model="crustForm.is_active" /> Ativa no cardápio
                </Label>
                <DialogFooter>
                    <Button type="submit" :disabled="crustForm.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="adjustOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>Reajustar preços</DialogTitle>
                <DialogDescription
                    >Aplica o percentual em todos os preços preenchidos da
                    tabela.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="submitAdjust">
                <div class="grid gap-2">
                    <Label for="adjust-percent">Percentual (%)</Label>
                    <Input
                        id="adjust-percent"
                        v-model="adjust.percent"
                        inputmode="decimal"
                        placeholder="Ex.: 10 ou -5"
                    />
                    <InputError :message="adjust.errors.percent" />
                </div>
                <div class="grid gap-2">
                    <Label for="adjust-target">Aplicar em</Label>
                    <NativeSelect id="adjust-target" v-model="adjust.target">
                        <option value="all">Sabores e bordas</option>
                        <option value="groups">Só sabores</option>
                        <option value="crusts">Só bordas</option>
                    </NativeSelect>
                </div>
                <p
                    v-if="prices.isDirty"
                    class="text-xs text-amber-700 dark:text-amber-300"
                >
                    Salve a tabela antes: as alterações não salvas serão
                    descartadas.
                </p>
                <DialogFooter>
                    <Button type="submit" :disabled="adjust.processing"
                        >Aplicar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
