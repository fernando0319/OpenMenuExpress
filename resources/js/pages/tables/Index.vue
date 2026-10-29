<script setup lang="ts">
import { Head, Link, usePage, usePoll } from '@inertiajs/vue3';
import { Users } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { formatCents } from '@/lib/money';
import { edit as tablesSettings } from '@/routes/store-settings/tables';
import { index, show } from '@/routes/tables';
import type { TableTile } from '@/types/tables';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Mesas', href: index() }],
    },
});

const props = defineProps<{
    tables: TableTile[];
    hasTables: boolean;
}>();

usePoll(10_000, { only: ['tables'] });

const page = usePage();
const canManageStore = computed(
    () => page.props.currentStore?.canManageStore ?? false,
);

// Ticks every half minute so the elapsed time stays fresh between polls.
const now = ref(Date.now());
const ticker = setInterval(() => (now.value = Date.now()), 30_000);
onBeforeUnmount(() => clearInterval(ticker));

function elapsed(openedAt: string | null): string {
    if (!openedAt) {
        return '';
    }

    const minutes = Math.max(
        0,
        Math.floor((now.value - new Date(openedAt).getTime()) / 60_000),
    );

    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)}h${String(minutes % 60).padStart(2, '0')}`;
}

const counts = computed(() => ({
    free: props.tables.filter((table) => table.status === 'free').length,
    busy: props.tables.filter((table) => table.status !== 'free').length,
}));

const tileClass: Record<TableTile['status'], string> = {
    free: 'border-dashed hover:border-primary',
    open: 'border-primary bg-primary/5',
    closing:
        'border-amber-500 bg-amber-50 dark:border-amber-600 dark:bg-amber-950',
    joined: 'border-primary/50 bg-primary/5',
};
</script>

<template>
    <Head title="Mesas" />

    <div class="flex flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <Heading
                title="Mesas"
                :description="`${counts.busy} ocupada(s) · ${counts.free} livre(s)`"
            />
        </div>

        <div
            v-if="tables.length === 0"
            class="flex flex-col items-start gap-3 rounded-xl border border-dashed p-6 text-sm"
        >
            <p>
                {{
                    hasTables
                        ? 'Todas as mesas estão desativadas.'
                        : 'Nenhuma mesa cadastrada ainda.'
                }}
            </p>
            <Button v-if="canManageStore" as-child>
                <Link :href="tablesSettings()">Cadastrar mesas</Link>
            </Button>
            <p v-else class="text-muted-foreground">
                Peça ao responsável da loja para cadastrar as mesas.
            </p>
        </div>

        <div
            v-else
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6"
        >
            <Link
                v-for="table in tables"
                :key="table.id"
                :href="show(table.joinedTo?.id ?? table.id)"
                class="flex min-h-28 flex-col gap-1 rounded-xl border-2 p-3 transition-colors"
                :class="tileClass[table.status]"
            >
                <span class="flex items-center justify-between gap-2">
                    <span class="font-semibold">{{ table.name }}</span>
                    <span
                        v-if="table.people || table.seats"
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Users class="size-3.5" />
                        {{ table.people ?? table.seats }}
                    </span>
                </span>
                <template v-if="table.status === 'joined'">
                    <span class="mt-auto text-xs font-medium text-primary"
                        >Junta com a {{ table.joinedTo?.name }}</span
                    >
                </template>
                <template v-else-if="table.status === 'free'">
                    <span class="mt-auto text-sm text-muted-foreground"
                        >Livre</span
                    >
                </template>
                <template v-else>
                    <span
                        v-if="table.customerName"
                        class="truncate text-xs text-muted-foreground"
                        >{{ table.customerName }}</span
                    >
                    <span
                        v-if="table.joinedNames.length > 0"
                        class="truncate text-xs text-muted-foreground"
                        >+ {{ table.joinedNames.join(', ') }}</span
                    >
                    <span
                        class="mt-auto text-xs font-medium"
                        :class="
                            table.status === 'closing'
                                ? 'text-amber-700 dark:text-amber-400'
                                : 'text-primary'
                        "
                    >
                        {{
                            table.status === 'closing'
                                ? 'Conta pedida'
                                : `Ocupada · ${elapsed(table.openedAt)}`
                        }}
                    </span>
                    <span class="text-lg font-semibold">{{
                        formatCents(table.consumedCents)
                    }}</span>
                </template>
            </Link>
        </div>
    </div>
</template>
