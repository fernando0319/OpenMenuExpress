<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import OptionGroupController from '@/actions/App/Http/Controllers/Catalog/OptionGroupController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/catalog/option-groups';
import type { OptionGroupRow } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Grupos de opções', href: index() }],
    },
});

defineProps<{
    groups: OptionGroupRow[];
}>();

function choicesLabel(group: OptionGroupRow): string {
    if (group.min_choices === 0) {
        return `Opcional, até ${group.max_choices}`;
    }

    return group.min_choices === group.max_choices
        ? `Obrigatório, ${group.min_choices}`
        : `Obrigatório, ${group.min_choices} a ${group.max_choices}`;
}

function destroy(group: OptionGroupRow): void {
    const warning =
        group.products_count > 0
            ? ` Ele será removido de ${group.products_count} produto(s).`
            : '';

    if (!confirm(`Excluir o grupo "${group.name}"?${warning}`)) {
        return;
    }

    router.delete(OptionGroupController.destroy.url(group.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Grupos de opções" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Grupos de opções"
                description="Adicionais, sabores, tamanhos e pontos de carne. Um grupo pode ser usado em vários produtos."
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    Novo grupo
                </Link>
            </Button>
        </div>

        <div
            v-if="groups.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhum grupo ainda. Crie um grupo como "Adicionais" ou "Sabores" e
            depois ligue-o aos produtos.
        </div>

        <div v-else class="overflow-hidden rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Nome</th>
                        <th class="px-4 py-2 font-medium">Escolhas</th>
                        <th class="px-4 py-2 font-medium">Preço</th>
                        <th class="px-4 py-2 font-medium">Opções</th>
                        <th class="px-4 py-2 font-medium">Produtos</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="group in groups"
                        :key="group.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2 font-medium">
                            {{ group.name }}
                            <Badge
                                v-if="!group.is_active"
                                variant="secondary"
                                class="ml-2"
                                >Inativo</Badge
                            >
                        </td>
                        <td class="px-4 py-2">{{ choicesLabel(group) }}</td>
                        <td class="px-4 py-2">
                            {{ group.pricing_mode_label }}
                        </td>
                        <td class="px-4 py-2">{{ group.options_count }}</td>
                        <td class="px-4 py-2">{{ group.products_count }}</td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <Button variant="ghost" size="icon" as-child>
                                <Link
                                    :href="edit(group.id)"
                                    :aria-label="`Editar ${group.name}`"
                                >
                                    <Pencil />
                                </Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Excluir ${group.name}`"
                                @click="destroy(group)"
                            >
                                <Trash2 />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
