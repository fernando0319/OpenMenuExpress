<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import TablesController from '@/actions/App/Http/Controllers/StoreSettings/TablesController';
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
import { edit } from '@/routes/store-settings/tables';

type TableRow = {
    id: number;
    name: string;
    seats: number | null;
    isActive: boolean;
    hasHistory: boolean;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mesas e taxa de serviço', href: edit() }],
    },
});

const props = defineProps<{
    serviceFeePercent: number;
    tables: TableRow[];
}>();

const feeForm = useForm({ service_fee_percent: props.serviceFeePercent });

function saveFee(): void {
    feeForm.put(TablesController.update.url(), { preserveScroll: true });
}

const rangeForm = useForm({ from: '1', to: '10', seats: '4' });

function createRange(): void {
    rangeForm.post(TablesController.store.url(), { preserveScroll: true });
}

const dialogOpen = ref(false);
const editing = ref<TableRow | null>(null);
const tableForm = useForm({ name: '', seats: '', is_active: true });

function openTable(table: TableRow | null): void {
    editing.value = table;
    tableForm.name = table?.name ?? '';
    tableForm.seats = table?.seats ? String(table.seats) : '';
    tableForm.is_active = table?.isActive ?? true;
    tableForm.clearErrors();
    dialogOpen.value = true;
}

function saveTable(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value) {
        tableForm.put(
            TablesController.updateTable.url(editing.value.id),
            options,
        );
    } else {
        tableForm.post(TablesController.store.url(), options);
    }
}

function destroy(table: TableRow): void {
    if (confirm(`Excluir a ${table.name}?`)) {
        router.delete(TablesController.destroy.url(table.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Mesas e taxa de serviço" />

    <div class="flex max-w-3xl flex-col gap-8 p-4">
        <section class="flex flex-col gap-4">
            <Heading
                title="Taxa de serviço"
                description="Sugerida no fechamento da conta das mesas. O cliente pode recusar; use 0 para não cobrar."
            />
            <form class="flex items-end gap-3" @submit.prevent="saveFee">
                <div class="grid gap-2">
                    <Label for="service-fee">Percentual</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="service-fee"
                            v-model.number="feeForm.service_fee_percent"
                            type="number"
                            min="0"
                            max="30"
                            class="w-24"
                        />
                        <span>%</span>
                    </div>
                    <InputError :message="feeForm.errors.service_fee_percent" />
                </div>
                <Button type="submit" :disabled="feeForm.processing"
                    >Salvar</Button
                >
            </form>
        </section>

        <section class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center gap-3">
                <Heading
                    title="Mesas"
                    description="Os garçons abrem as mesas pelo celular, em Mesas."
                />
                <Button
                    class="ml-auto"
                    variant="outline"
                    @click="openTable(null)"
                >
                    <Plus />
                    Nova mesa
                </Button>
            </div>

            <form
                class="flex flex-wrap items-end gap-3 rounded-xl border p-4"
                @submit.prevent="createRange"
            >
                <p class="w-full text-sm font-medium">Criar mesas numeradas</p>
                <div class="grid gap-2">
                    <Label for="range-from">Da mesa</Label>
                    <Input
                        id="range-from"
                        v-model="rangeForm.from"
                        type="number"
                        min="1"
                        class="w-24"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="range-to">até a</Label>
                    <Input
                        id="range-to"
                        v-model="rangeForm.to"
                        type="number"
                        min="1"
                        class="w-24"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="range-seats">Lugares</Label>
                    <Input
                        id="range-seats"
                        v-model="rangeForm.seats"
                        type="number"
                        min="1"
                        class="w-24"
                    />
                </div>
                <Button type="submit" :disabled="rangeForm.processing"
                    >Criar</Button
                >
                <InputError
                    class="w-full"
                    :message="rangeForm.errors.to ?? rangeForm.errors.from"
                />
            </form>

            <p
                v-if="tables.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nenhuma mesa cadastrada.
            </p>
            <ul v-else class="flex flex-col divide-y rounded-xl border">
                <li
                    v-for="table in tables"
                    :key="table.id"
                    class="flex items-center gap-3 p-3 text-sm"
                >
                    <span class="font-medium">{{ table.name }}</span>
                    <span v-if="table.seats" class="text-muted-foreground"
                        >{{ table.seats }} lugares</span
                    >
                    <Badge v-if="!table.isActive" variant="secondary"
                        >Desativada</Badge
                    >
                    <div class="ml-auto flex gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            :aria-label="`Editar ${table.name}`"
                            @click="openTable(table)"
                        >
                            <Pencil />
                        </Button>
                        <Button
                            v-if="!table.hasHistory"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Excluir ${table.name}`"
                            @click="destroy(table)"
                        >
                            <Trash2 />
                        </Button>
                    </div>
                </li>
            </ul>
        </section>
    </div>

    <Dialog v-model:open="dialogOpen">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Editar mesa' : 'Nova mesa'
                }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="saveTable">
                <div class="grid gap-2">
                    <Label for="table-name">Nome</Label>
                    <Input
                        id="table-name"
                        v-model="tableForm.name"
                        maxlength="40"
                        placeholder="Mesa 1, Varanda, Balcão 2…"
                    />
                    <InputError :message="tableForm.errors.name" />
                </div>
                <div class="grid gap-2">
                    <Label for="table-seats">Lugares (opcional)</Label>
                    <Input
                        id="table-seats"
                        v-model="tableForm.seats"
                        type="number"
                        min="1"
                    />
                    <InputError :message="tableForm.errors.seats" />
                </div>
                <Label class="flex items-center gap-2 font-normal">
                    <Checkbox v-model="tableForm.is_active" />
                    Mesa em uso
                </Label>
                <DialogFooter>
                    <Button type="submit" :disabled="tableForm.processing">
                        Salvar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
