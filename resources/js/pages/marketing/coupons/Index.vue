<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import CouponController from '@/actions/App/Http/Controllers/Marketing/CouponController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/marketing/coupons';

type CouponRow = {
    id: number;
    code: string;
    description: string | null;
    benefit: string;
    uses: number;
    usageLimit: number | null;
    status: string;
    endsAt: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cupons', href: index() }],
    },
});

defineProps<{
    coupons: CouponRow[];
}>();

function destroy(coupon: CouponRow): void {
    if (
        !confirm(
            `Excluir o cupom ${coupon.code}? Os pedidos que já o usaram continuam com o desconto.`,
        )
    ) {
        return;
    }

    router.delete(CouponController.destroy.url(coupon.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Cupons" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Cupons de desconto"
                description="O cliente digita o código no carrinho. Também dá para aplicar um cupom por um banner."
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    Novo cupom
                </Link>
            </Button>
        </div>

        <p
            v-if="coupons.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            Nenhum cupom ainda.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2 font-medium">Código</th>
                        <th class="px-4 py-2 font-medium">Desconto</th>
                        <th class="px-4 py-2 font-medium">Usos</th>
                        <th class="px-4 py-2 font-medium">Validade</th>
                        <th class="px-4 py-2 font-medium">Situação</th>
                        <th class="px-4 py-2">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="coupon in coupons"
                        :key="coupon.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">
                            <span class="font-mono font-medium">{{
                                coupon.code
                            }}</span>
                            <span
                                v-if="coupon.description"
                                class="block text-xs text-muted-foreground"
                                >{{ coupon.description }}</span
                            >
                        </td>
                        <td class="px-4 py-2">{{ coupon.benefit }}</td>
                        <td class="px-4 py-2">
                            {{ coupon.uses
                            }}<template v-if="coupon.usageLimit">
                                / {{ coupon.usageLimit }}</template
                            >
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{
                                coupon.endsAt
                                    ? `até ${coupon.endsAt}`
                                    : 'Sem fim'
                            }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="
                                    coupon.status === 'Ativo'
                                        ? 'default'
                                        : 'secondary'
                                "
                                >{{ coupon.status }}</Badge
                            >
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <Button variant="ghost" size="icon" as-child>
                                <Link
                                    :href="edit(coupon.id)"
                                    :aria-label="`Editar ${coupon.code}`"
                                >
                                    <Pencil />
                                </Link>
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                :aria-label="`Excluir ${coupon.code}`"
                                @click="destroy(coupon)"
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
