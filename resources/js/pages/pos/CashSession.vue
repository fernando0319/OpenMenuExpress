<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Printer } from '@lucide/vue';
import CashController from '@/actions/App/Http/Controllers/Pos/CashController';
import Heading from '@/components/Heading.vue';
import CashSessionReport from '@/components/pos/CashSessionReport.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/cash';
import type { CashSessionDetail } from '@/types/cash';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Caixa', href: index() }],
    },
});

const props = defineProps<{
    session: CashSessionDetail;
}>();

const dateTime = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateTime.format(new Date(value)) : '';
}

function print(): void {
    router.post(
        CashController.print.url(props.session.id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Fechamento de caixa" />

    <div class="flex max-w-5xl flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                :title="session.isOpen ? 'Caixa aberto' : 'Fechamento de caixa'"
                :description="`${formatDate(session.openedAt)} até ${session.closedAt ? formatDate(session.closedAt) : 'agora'}${session.closedBy ? ` · fechado por ${session.closedBy}` : ''}`"
            />
            <Button variant="outline" class="ml-auto" @click="print">
                <Printer />
                Imprimir
            </Button>
        </div>

        <CashSessionReport :session="session" />
    </div>
</template>
