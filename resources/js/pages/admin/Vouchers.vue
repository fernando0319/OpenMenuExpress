<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Copy, Plus } from '@lucide/vue';
import { ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BillingAdminController from '@/actions/App/Http/Controllers/Admin/BillingAdminController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/vouchers';
import type { Paginated } from '@/types/store';

type VoucherRow = {
    id: number;
    code: string;
    plan: string;
    days: number;
    uses: number;
    maxUses: number;
    expiresAt: string | null;
    note: string | null;
    status: string;
    isActive: boolean;
    createdAt: string | null;
};

const props = defineProps<{
    vouchers: Paginated<VoucherRow>;
    plans: { id: number; name: string }[];
    filters: { status: string };
    generated: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administração', href: dashboard() },
            { title: 'Vouchers', href: index() },
        ],
    },
});

const open = ref(false);
const form = useForm({
    plan_id: props.plans[0]?.id ?? null,
    days: 30,
    quantity: 1,
    max_redemptions: 1,
    expires_at: '',
    note: '',
});

function generate(): void {
    form.post(BillingAdminController.generateVouchers.url(), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}

const status = ref(props.filters.status);
watch(status, (value) =>
    router.get(
        index.url(),
        { status: value },
        { preserveState: true, replace: true },
    ),
);

async function copy(text: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copiado.');
    } catch {
        toast.error('Não foi possível copiar.');
    }
}

function toggle(voucher: VoucherRow): void {
    router.patch(
        BillingAdminController.toggleVoucher.url(voucher.id),
        {},
        { preserveScroll: true },
    );
}

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

function statusVariant(
    label: string,
): 'default' | 'secondary' | 'outline' | 'destructive' {
    return label === 'Disponível'
        ? 'default'
        : label === 'Usado'
          ? 'secondary'
          : 'outline';
}
</script>

<template>
    <Head title="Vouchers" />

    <div class="flex max-w-6xl flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                title="Vouchers"
                description="Códigos que o lojista ativa em Assinatura para somar dias de um plano."
            />
            <div class="ml-auto flex gap-2">
                <NativeSelect
                    v-model="status"
                    class="w-40"
                    aria-label="Filtrar"
                >
                    <option value="all">Todos</option>
                    <option value="available">Disponíveis</option>
                    <option value="used">Usados</option>
                </NativeSelect>
                <Button :disabled="plans.length === 0" @click="open = true"
                    ><Plus /> Gerar vouchers</Button
                >
            </div>
        </div>

        <p
            v-if="plans.length === 0"
            class="rounded-xl border border-dashed p-4 text-sm text-muted-foreground"
        >
            Crie um plano antes de gerar vouchers.
        </p>

        <section
            v-if="generated.length > 0"
            class="rounded-2xl border border-primary/40 bg-primary/5 p-5"
        >
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="font-semibold">Códigos gerados agora</h2>
                <Button
                    size="sm"
                    variant="outline"
                    @click="copy(generated.join('\n'))"
                    ><Copy /> Copiar todos</Button
                >
            </div>
            <ul class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <li v-for="code in generated" :key="code">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 rounded-lg border bg-background px-3 py-2 font-mono text-sm"
                        @click="copy(code)"
                    >
                        {{ code }}
                        <Copy class="size-3.5 text-muted-foreground" />
                    </button>
                </li>
            </ul>
        </section>

        <div class="overflow-x-auto rounded-2xl border bg-card shadow-xs">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="p-3 font-medium">Código</th>
                        <th class="p-3 font-medium">Plano</th>
                        <th class="p-3 text-right font-medium">Dias</th>
                        <th class="p-3 text-right font-medium">Usos</th>
                        <th class="p-3 font-medium">Validade</th>
                        <th class="p-3 font-medium">Situação</th>
                        <th class="p-3"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-if="vouchers.data.length === 0">
                        <td
                            colspan="7"
                            class="p-6 text-center text-muted-foreground"
                        >
                            Nenhum voucher.
                        </td>
                    </tr>
                    <tr v-for="voucher in vouchers.data" :key="voucher.id">
                        <td class="p-3">
                            <button
                                type="button"
                                class="flex items-center gap-2 font-mono"
                                @click="copy(voucher.code)"
                            >
                                {{ voucher.code }}
                                <Copy class="size-3.5 text-muted-foreground" />
                            </button>
                            <p
                                v-if="voucher.note"
                                class="text-xs text-muted-foreground"
                            >
                                {{ voucher.note }}
                            </p>
                        </td>
                        <td class="p-3">{{ voucher.plan }}</td>
                        <td class="p-3 text-right">{{ voucher.days }}</td>
                        <td class="p-3 text-right">
                            {{ voucher.uses }}/{{ voucher.maxUses }}
                        </td>
                        <td class="p-3">
                            {{
                                voucher.expiresAt
                                    ? date.format(new Date(voucher.expiresAt))
                                    : '—'
                            }}
                        </td>
                        <td class="p-3">
                            <Badge :variant="statusVariant(voucher.status)">{{
                                voucher.status
                            }}</Badge>
                        </td>
                        <td class="p-3 text-right">
                            <Button
                                size="sm"
                                variant="ghost"
                                @click="toggle(voucher)"
                                >{{
                                    voucher.isActive ? 'Desativar' : 'Reativar'
                                }}</Button
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="vouchers.last_page > 1" class="flex flex-wrap gap-1">
            <template v-for="link in vouchers.links" :key="link.label">
                <Button
                    v-if="link.url"
                    as-child
                    size="sm"
                    :variant="link.active ? 'default' : 'outline'"
                >
                    <!-- eslint-disable-next-line vue/no-v-html -->
                    <Link :href="link.url" preserve-scroll
                        ><span v-html="link.label"
                    /></Link>
                </Button>
            </template>
        </nav>
    </div>

    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Gerar vouchers</DialogTitle>
                <DialogDescription
                    >Cada código soma os dias do plano escolhido à assinatura da
                    loja que ativar.</DialogDescription
                >
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="generate">
                <div class="grid gap-2">
                    <Label for="voucher-plan">Plano</Label>
                    <NativeSelect id="voucher-plan" v-model="form.plan_id">
                        <option
                            v-for="plan in plans"
                            :key="plan.id"
                            :value="plan.id"
                        >
                            {{ plan.name }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.plan_id" />
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="grid gap-2">
                        <Label for="voucher-days">Dias</Label>
                        <Input
                            id="voucher-days"
                            v-model.number="form.days"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.days" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="voucher-qty">Quantidade</Label>
                        <Input
                            id="voucher-qty"
                            v-model.number="form.quantity"
                            type="number"
                            min="1"
                            max="100"
                        />
                        <InputError :message="form.errors.quantity" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="voucher-uses">Usos cada</Label>
                        <Input
                            id="voucher-uses"
                            v-model.number="form.max_redemptions"
                            type="number"
                            min="1"
                        />
                        <InputError :message="form.errors.max_redemptions" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="voucher-expires">Válido até (opcional)</Label>
                    <Input
                        id="voucher-expires"
                        v-model="form.expires_at"
                        type="date"
                    />
                    <InputError :message="form.errors.expires_at" />
                </div>
                <div class="grid gap-2">
                    <Label for="voucher-note">Anotação (opcional)</Label>
                    <Input
                        id="voucher-note"
                        v-model="form.note"
                        maxlength="120"
                        placeholder="Ex.: PIX da Pizzaria do Zé, novembro"
                    />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing"
                        >Gerar</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
