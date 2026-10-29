<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BillingAdminController from '@/actions/App/Http/Controllers/Admin/BillingAdminController';
import FreeUsageMeter from '@/components/FreeUsageMeter.vue';
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as invoicesIndex } from '@/routes/admin/invoices';

/**
 * Admin view of a store's subscription: status and manual release (payment received outside).
 */
const props = defineProps<{
    storeId: number;
    subscription: {
        status: string;
        label: string;
        planName: string | null;
        endsAt: string | null;
        daysLeft: number | null;
        planId: number | null;
        isComplimentary: boolean;
        trialEndsAt: string | null;
        paidUntil: string | null;
        free: { limitCents: number; salesCents: number } | null;
    };
    plans: { id: number; name: string; is_active: boolean }[];
    billingEnabled: boolean;
}>();

const date = new Intl.DateTimeFormat('pt-BR', { dateStyle: 'short' });

function formatDate(value: string | null): string {
    return value ? date.format(new Date(value)) : '—';
}

const extendForm = useForm({
    days: 30,
    plan_id: props.subscription.planId,
    note: '',
});

function extend(): void {
    extendForm.post(BillingAdminController.extend.url(props.storeId), {
        preserveScroll: true,
        onSuccess: () => extendForm.reset('note'),
    });
}

const plan = ref<number | null>(props.subscription.planId);

function savePlan(): void {
    router.put(
        BillingAdminController.setPlan.url(props.storeId),
        { plan_id: plan.value },
        { preserveScroll: true },
    );
}

function toggleComplimentary(): void {
    const next = !props.subscription.isComplimentary;
    const note = next ? prompt('Motivo da cortesia (opcional):') : null;

    if (next && note === null) {
        return;
    }

    router.put(
        BillingAdminController.complimentary.url(props.storeId),
        { complimentary: next, note: note ?? '' },
        { preserveScroll: true },
    );
}

const tone = computed(() => {
    switch (props.subscription.status) {
        case 'expired':
            return 'destructive';
        case 'grace':
            return 'outline';
        default:
            return 'secondary';
    }
});
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border bg-card p-5 text-sm shadow-xs"
    >
        <div class="flex flex-wrap items-center gap-2">
            <h2 class="font-semibold">Assinatura</h2>
            <Badge :variant="tone">{{ subscription.label }}</Badge>
            <Badge v-if="subscription.planName" variant="outline">{{
                subscription.planName
            }}</Badge>
            <span v-if="!billingEnabled" class="text-xs text-muted-foreground"
                >(cobrança desligada nesta instalação)</span
            >
            <Link
                :href="
                    invoicesIndex({ query: { store: storeId, status: 'all' } })
                "
                class="ml-auto text-xs text-primary hover:underline"
                >Ver faturas</Link
            >
        </div>

        <dl class="grid gap-1 sm:grid-cols-3">
            <div>
                <dt class="text-muted-foreground">Teste até</dt>
                <dd>{{ formatDate(subscription.trialEndsAt) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Pago até</dt>
                <dd>{{ formatDate(subscription.paidUntil) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Dias restantes</dt>
                <dd>
                    {{
                        subscription.daysLeft === null
                            ? '—'
                            : subscription.daysLeft < 0
                              ? `venceu há ${-subscription.daysLeft} dia(s)`
                              : subscription.daysLeft
                    }}
                </dd>
            </div>
        </dl>

        <FreeUsageMeter
            v-if="subscription.free"
            :limit-cents="subscription.free.limitCents"
            :sales-cents="subscription.free.salesCents"
        />

        <form
            class="grid gap-3 rounded-xl border p-4 sm:grid-cols-[6rem_1fr_1fr_auto] sm:items-end"
            @submit.prevent="extend"
        >
            <div class="grid gap-1.5">
                <Label for="extend-days">Liberar dias</Label>
                <Input
                    id="extend-days"
                    v-model.number="extendForm.days"
                    type="number"
                    min="1"
                />
            </div>
            <div class="grid gap-1.5">
                <Label for="extend-plan">Plano</Label>
                <NativeSelect id="extend-plan" v-model="extendForm.plan_id">
                    <option :value="null">Manter o atual</option>
                    <option
                        v-for="item in plans"
                        :key="item.id"
                        :value="item.id"
                    >
                        {{ item.name }}
                    </option>
                </NativeSelect>
            </div>
            <div class="grid gap-1.5">
                <Label for="extend-note">Anotação</Label>
                <Input
                    id="extend-note"
                    v-model="extendForm.note"
                    maxlength="120"
                    placeholder="Ex.: PIX recebido em 20/10"
                />
            </div>
            <Button type="submit" :disabled="extendForm.processing"
                >Liberar</Button
            >
            <InputError
                class="sm:col-span-4"
                :message="extendForm.errors.days"
            />
        </form>

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1.5">
                <Label for="change-plan"
                    >Trocar plano (sem mudar o vencimento)</Label
                >
                <div class="flex gap-2">
                    <NativeSelect id="change-plan" v-model="plan" class="w-60">
                        <option :value="null">Sem plano (tudo liberado)</option>
                        <option
                            v-for="item in plans"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </NativeSelect>
                    <Button
                        variant="outline"
                        :disabled="plan === subscription.planId"
                        @click="savePlan"
                        >Salvar</Button
                    >
                </div>
            </div>
            <Button
                class="ml-auto"
                :variant="
                    subscription.isComplimentary ? 'outline' : 'secondary'
                "
                @click="toggleComplimentary"
            >
                {{
                    subscription.isComplimentary
                        ? 'Remover cortesia'
                        : 'Dar cortesia (sem vencimento)'
                }}
            </Button>
        </div>
    </section>
</template>
