<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, ReceiptText, Search, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import SupplierController from '@/actions/App/Http/Controllers/Finance/SupplierController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TextareaField from '@/components/TextareaField.vue';
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
import { formatCents } from '@/lib/money';
import { overview, payables } from '@/routes/finance';
import { index } from '@/routes/finance/suppliers';
import { create } from '@/routes/finance/titles';

type SupplierRow = {
    id: number;
    name: string;
    document: string | null;
    documentLabel: string | null;
    contactName: string | null;
    phone: string | null;
    email: string | null;
    notes: string | null;
    isActive: boolean;
    titlesCount: number;
    openCents: number;
};

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Financeiro', href: overview() },
            { title: 'Fornecedores', href: index() },
        ],
    },
});

const props = defineProps<{
    suppliers: SupplierRow[];
    search: string;
}>();

const search = ref(props.search);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(
            index.url({ query: { q: value || undefined } }),
            {},
            { preserveState: true, replace: true },
        );
    }, 350);
});

/** 5541999998888 → (41) 99999-8888 */
function formatPhone(phone: string | null): string {
    if (!phone) {
        return '—';
    }

    const local = phone.replace(/^55/, '');

    return local.length === 11
        ? `(${local.slice(0, 2)}) ${local.slice(2, 7)}-${local.slice(7)}`
        : `(${local.slice(0, 2)}) ${local.slice(2, 6)}-${local.slice(6)}`;
}

const open = ref(false);
const editing = ref<SupplierRow | null>(null);
const form = useForm({
    name: '',
    document: '',
    contact_name: '',
    phone: '',
    email: '',
    notes: '',
    is_active: true,
});

function start(supplier: SupplierRow | null): void {
    editing.value = supplier;
    form.clearErrors();
    form.name = supplier?.name ?? '';
    form.document = supplier?.documentLabel ?? '';
    form.contact_name = supplier?.contactName ?? '';
    form.phone = supplier?.phone ? formatPhone(supplier.phone) : '';
    form.email = supplier?.email ?? '';
    form.notes = supplier?.notes ?? '';
    form.is_active = supplier?.isActive ?? true;
    open.value = true;
}

function save(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(SupplierController.update.url(editing.value.id), options);
    } else {
        form.post(SupplierController.store.url(), options);
    }
}

function destroy(supplier: SupplierRow): void {
    const message =
        supplier.titlesCount > 0
            ? `${supplier.name} tem lançamentos, então será desativado (o histórico continua). Confirmar?`
            : `Excluir ${supplier.name}?`;

    if (confirm(message)) {
        router.delete(SupplierController.destroy.url(supplier.id), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Head title="Fornecedores" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Fornecedores"
                description="Quem vende para a loja. Cada fornecedor mostra quanto ainda falta pagar."
            />
            <Button @click="start(null)"><Plus /> Novo fornecedor</Button>
        </div>

        <div class="relative max-w-md">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                v-model="search"
                class="pl-9"
                placeholder="Buscar por nome, contato, CNPJ ou telefone"
                aria-label="Buscar fornecedor"
            />
        </div>

        <p
            v-if="suppliers.length === 0"
            class="rounded-2xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            {{
                search
                    ? 'Nenhum fornecedor encontrado.'
                    : 'Nenhum fornecedor cadastrado ainda.'
            }}
        </p>

        <div
            v-else
            class="overflow-x-auto rounded-2xl border bg-card shadow-xs"
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Fornecedor</th>
                        <th
                            class="hidden px-4 py-2.5 font-medium md:table-cell"
                        >
                            CPF/CNPJ
                        </th>
                        <th
                            class="hidden px-4 py-2.5 font-medium sm:table-cell"
                        >
                            Telefone
                        </th>
                        <th class="px-4 py-2.5 text-right font-medium">
                            A pagar
                        </th>
                        <th class="px-4 py-2.5">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="supplier in suppliers"
                        :key="supplier.id"
                        class="border-t"
                        :class="{ 'text-muted-foreground': !supplier.isActive }"
                    >
                        <td class="px-4 py-3">
                            <span class="font-medium">{{ supplier.name }}</span>
                            <Badge
                                v-if="!supplier.isActive"
                                variant="outline"
                                class="ml-2"
                                >Inativo</Badge
                            >
                            <span
                                v-if="supplier.contactName || supplier.email"
                                class="block text-xs text-muted-foreground"
                            >
                                {{
                                    [supplier.contactName, supplier.email]
                                        .filter(Boolean)
                                        .join(' · ')
                                }}
                            </span>
                        </td>
                        <td class="hidden px-4 py-3 tabular-nums md:table-cell">
                            {{ supplier.documentLabel ?? '—' }}
                        </td>
                        <td
                            class="hidden px-4 py-3 whitespace-nowrap sm:table-cell"
                        >
                            {{ formatPhone(supplier.phone) }}
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Link
                                v-if="supplier.openCents > 0"
                                :href="
                                    payables({
                                        query: { supplier: supplier.id },
                                    })
                                "
                                class="font-medium tabular-nums hover:underline"
                                >{{ formatCents(supplier.openCents) }}</Link
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <Button variant="ghost" size="icon" as-child>
                                <Link
                                    :href="
                                        create({
                                            query: {
                                                type: 'payable',
                                                supplier: supplier.id,
                                            },
                                        })
                                    "
                                    :aria-label="`Nova conta de ${supplier.name}`"
                                    title="Nova conta a pagar"
                                    ><ReceiptText
                                /></Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Editar ${supplier.name}`"
                                @click="start(supplier)"
                                ><Pencil
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Excluir ${supplier.name}`"
                                @click="destroy(supplier)"
                                ><Trash2
                            /></Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    editing ? 'Editar fornecedor' : 'Novo fornecedor'
                }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="supplier-name">Nome ou razão social</Label>
                    <Input
                        id="supplier-name"
                        v-model="form.name"
                        maxlength="120"
                    />
                    <InputError :message="form.errors.name" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="supplier-document">CPF ou CNPJ</Label>
                        <Input
                            id="supplier-document"
                            v-model="form.document"
                            inputmode="numeric"
                        />
                        <InputError :message="form.errors.document" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="supplier-contact">Contato</Label>
                        <Input
                            id="supplier-contact"
                            v-model="form.contact_name"
                            maxlength="80"
                            placeholder="Ex.: João (vendedor)"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="supplier-phone">Telefone / WhatsApp</Label>
                        <Input
                            id="supplier-phone"
                            v-model="form.phone"
                            inputmode="tel"
                        />
                        <InputError :message="form.errors.phone" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="supplier-email">E-mail</Label>
                        <Input
                            id="supplier-email"
                            v-model="form.email"
                            type="email"
                            maxlength="120"
                        />
                        <InputError :message="form.errors.email" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="supplier-notes">Observações</Label>
                    <TextareaField
                        id="supplier-notes"
                        v-model="form.notes"
                        maxlength="2000"
                        placeholder="Dia de entrega, condições, chave PIX…"
                    />
                </div>
                <Label
                    v-if="editing"
                    class="flex items-center gap-2 font-normal"
                >
                    <Checkbox v-model="form.is_active" /> Ativo
                </Label>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing"
                        >Salvar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
