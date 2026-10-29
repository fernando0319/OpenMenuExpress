<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Pencil, Plus, Trash2, X } from '@lucide/vue';
import { ref } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Finance/CategoryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { overview } from '@/routes/finance';
import { index } from '@/routes/finance/categories';
import type { FinanceKind } from '@/types/finance';

type CategoryRow = {
    id: number;
    name: string;
    isActive: boolean;
    titlesCount: number;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Financeiro', href: overview() },
            { title: 'Categorias', href: index() },
        ],
    },
});

defineProps<{
    payable: CategoryRow[];
    receivable: CategoryRow[];
}>();

const groups: { type: FinanceKind; title: string; hint: string }[] = [
    { type: 'payable', title: 'Despesas', hint: 'Usadas nas contas a pagar.' },
    {
        type: 'receivable',
        title: 'Receitas',
        hint: 'Usadas nas contas a receber.',
    },
];

const addForms = {
    payable: useForm({ type: 'payable', name: '' }),
    receivable: useForm({ type: 'receivable', name: '' }),
};

function add(type: FinanceKind): void {
    addForms[type].post(CategoryController.store.url(), {
        preserveScroll: true,
        onSuccess: () => addForms[type].reset('name'),
    });
}

const editingId = ref<number | null>(null);
const editForm = useForm({ name: '', is_active: true });

function startEdit(category: CategoryRow): void {
    editingId.value = category.id;
    editForm.clearErrors();
    editForm.name = category.name;
    editForm.is_active = category.isActive;
}

function saveEdit(): void {
    if (editingId.value === null) {
        return;
    }

    editForm.put(CategoryController.update.url(editingId.value), {
        preserveScroll: true,
        onSuccess: () => {
            editingId.value = null;
        },
    });
}

function toggle(category: CategoryRow): void {
    router.put(
        CategoryController.update.url(category.id),
        { name: category.name, is_active: !category.isActive },
        { preserveScroll: true },
    );
}

function destroy(category: CategoryRow): void {
    const message =
        category.titlesCount > 0
            ? `"${category.name}" está em uso e será só desativada. Confirmar?`
            : `Excluir "${category.name}"?`;

    if (confirm(message)) {
        router.delete(CategoryController.destroy.url(category.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Categorias financeiras" />

    <div class="flex max-w-5xl flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Categorias financeiras"
            description="Organizam as contas e mostram para onde vai o dinheiro no resumo do mês."
        />

        <div class="grid gap-4 md:grid-cols-2">
            <section
                v-for="group in groups"
                :key="group.type"
                class="flex flex-col gap-3 rounded-2xl border bg-card p-5 shadow-xs"
            >
                <div>
                    <h2 class="font-semibold">{{ group.title }}</h2>
                    <p class="text-sm text-muted-foreground">
                        {{ group.hint }}
                    </p>
                </div>

                <form class="flex gap-2" @submit.prevent="add(group.type)">
                    <Input
                        v-model="addForms[group.type].name"
                        maxlength="60"
                        :placeholder="`Nova categoria de ${group.title.toLowerCase()}`"
                        :aria-label="`Nova categoria de ${group.title.toLowerCase()}`"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="addForms[group.type].processing"
                    >
                        <Plus /> Adicionar
                    </Button>
                </form>
                <InputError :message="addForms[group.type].errors.name" />

                <ul class="flex flex-col divide-y text-sm">
                    <li
                        v-for="category in group.type === 'payable'
                            ? payable
                            : receivable"
                        :key="category.id"
                        class="flex min-h-11 items-center gap-2 py-1.5"
                    >
                        <template v-if="editingId === category.id">
                            <form
                                class="flex flex-1 gap-1"
                                @submit.prevent="saveEdit"
                            >
                                <Input
                                    v-model="editForm.name"
                                    v-focus
                                    class="h-8"
                                    maxlength="60"
                                    aria-label="Nome"
                                />
                                <Button
                                    type="submit"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Salvar"
                                    ><Check
                                /></Button>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label="Cancelar"
                                    @click="editingId = null"
                                    ><X
                                /></Button>
                            </form>
                        </template>
                        <template v-else>
                            <span
                                class="flex-1"
                                :class="{
                                    'text-muted-foreground line-through':
                                        !category.isActive,
                                }"
                            >
                                {{ category.name }}
                            </span>
                            <span
                                v-if="category.titlesCount"
                                class="text-xs text-muted-foreground"
                                >{{ category.titlesCount }} lanç.</span
                            >
                            <Badge
                                as="button"
                                :variant="
                                    category.isActive ? 'secondary' : 'outline'
                                "
                                class="cursor-pointer"
                                @click="toggle(category)"
                                >{{
                                    category.isActive ? 'Ativa' : 'Inativa'
                                }}</Badge
                            >
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="`Renomear ${category.name}`"
                                @click="startEdit(category)"
                                ><Pencil
                            /></Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                :aria-label="`Excluir ${category.name}`"
                                @click="destroy(category)"
                                ><Trash2
                            /></Button>
                        </template>
                    </li>
                </ul>
                <InputError :message="editForm.errors.name" />
            </section>
        </div>
    </div>
</template>
