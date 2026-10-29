<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CategoryController from '@/actions/App/Http/Controllers/Catalog/CategoryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/catalog/categories';
import type { CategoryRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Categorias', href: index() }],
    },
});

defineProps<{
    categories: CategoryRow[];
}>();

const dialogOpen = ref(false);
const editing = ref<CategoryRow | null>(null);

const form = useForm({
    name: '',
    is_active: true,
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(category: CategoryRow): void {
    editing.value = category;
    form.name = category.name;
    form.is_active = category.is_active;
    form.clearErrors();
    dialogOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(CategoryController.update.url(editing.value.id), options);
    } else {
        form.post(CategoryController.store.url(), options);
    }
}

function destroy(category: CategoryRow): void {
    if (!confirm(`Excluir a categoria "${category.name}"?`)) {
        return;
    }

    router.delete(CategoryController.destroy.url(category.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Categorias" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Categorias"
                description="Organize o cardápio em seções, como Pizzas, Lanches e Bebidas."
            />
            <Button @click="openCreate">
                <Plus />
                Nova categoria
            </Button>
        </div>

        <div
            v-if="categories.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhuma categoria ainda. Crie a primeira para começar a cadastrar
            produtos.
        </div>

        <div v-else class="overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nome</th>
                        <th class="px-4 py-2 font-medium">Produtos</th>
                        <th class="px-4 py-2 font-medium">Situação</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="category in categories"
                        :key="category.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ category.name }}
                        </td>
                        <td class="px-4 py-2">{{ category.products_count }}</td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    category.is_active ? 'default' : 'secondary'
                                "
                            >
                                {{ category.is_active ? 'Ativa' : 'Oculta' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Editar ${category.name}`"
                                @click="openEdit(category)"
                            >
                                <Pencil />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Excluir ${category.name}`"
                                @click="destroy(category)"
                            >
                                <Trash2 />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent>
            <form class="space-y-6" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>
                        {{ editing ? 'Editar categoria' : 'Nova categoria' }}
                    </DialogTitle>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="category-name">Nome</Label>
                    <Input
                        id="category-name"
                        v-model="form.name"
                        required
                        placeholder="Ex.: Pizzas"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="flex items-center gap-2">
                    <Checkbox id="category-active" v-model="form.is_active" />
                    <Label for="category-active">Visível no cardápio</Label>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Salvar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
