<script setup lang="ts">
import {
    Check,
    ChevronLeft,
    Heart,
    Minus,
    Plus,
    UtensilsCrossed,
} from '@lucide/vue';
import {
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, toRef } from 'vue';
import PizzaBuilder from '@/components/menu/PizzaBuilder.vue';
import TextareaField from '@/components/TextareaField.vue';
import { useProductOptions } from '@/composables/useProductOptions';
import { formatCents } from '@/lib/money';
import type { CartItem, MenuProduct } from '@/types/menu';

/**
 * Product page of the customer menu: big photo, curved white panel, options and the add bar.
 * Full screen on phones, a centered card on larger screens.
 */
const props = defineProps<{
    product: MenuProduct | null;
    canOrder: boolean;
    favorite: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    add: [item: Omit<CartItem, 'key'>];
    toggleFavorite: [];
}>();

const options = useProductOptions(toRef(props, 'product'));
const { quantity, notes, missingGroups, totalCents } = options;

const onSale = computed(() =>
    props.product
        ? props.product.effectivePriceCents < props.product.priceCents
        : false,
);

const buttonLabel = computed(() => {
    if (!props.canOrder) {
        return 'Loja fechada';
    }

    if (props.product?.soldOut) {
        return 'Esgotado';
    }

    if (missingGroups.value.length > 0) {
        // The red badge next to the group shows which one is missing.
        return 'Escolha as opções';
    }

    return 'Adicionar';
});

function add(): void {
    const item = options.buildItem();

    if (item) {
        emit('add', item);
        open.value = false;
    }
}
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-slate-900/60 data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                v-if="product"
                class="fixed inset-0 z-50 flex flex-col overflow-hidden bg-white font-display shadow-2xl data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:slide-in-from-bottom-8 sm:inset-auto sm:top-1/2 sm:left-1/2 sm:max-h-[92dvh] sm:w-full sm:max-w-lg sm:-translate-x-1/2 sm:-translate-y-1/2 sm:rounded-[2rem]"
            >
                <div class="flex-1 overflow-y-auto">
                    <div
                        class="relative bg-[#1b1d22]"
                        :class="product.imageUrl ? 'h-80 sm:h-72' : 'h-48'"
                    >
                        <img
                            v-if="product.imageUrl"
                            :src="product.imageUrl"
                            :alt="product.name"
                            class="size-full object-cover"
                        />
                        <div
                            v-else
                            class="grid size-full place-items-center bg-linear-to-br from-brand to-brand-accent"
                        >
                            <UtensilsCrossed class="size-14 text-white/70" />
                        </div>
                        <div
                            class="absolute inset-x-0 top-0 h-28 bg-linear-to-b from-black/70 to-transparent"
                        />
                        <div
                            class="absolute inset-x-0 top-0 flex items-center gap-3 px-4 pt-[max(1rem,env(safe-area-inset-top))]"
                        >
                            <DialogClose
                                class="grid size-10 place-items-center rounded-full text-white hover:bg-white/10"
                                aria-label="Voltar"
                            >
                                <ChevronLeft class="size-6" />
                            </DialogClose>
                            <DialogTitle
                                class="flex-1 truncate pr-10 text-center font-semibold text-white"
                            >
                                {{ product.name }}
                            </DialogTitle>
                        </div>
                    </div>

                    <div
                        class="relative -mt-12 rounded-t-[2.75rem] bg-white px-6 pt-9 pb-6"
                    >
                        <button
                            type="button"
                            class="absolute -top-7 right-7 grid size-14 place-items-center rounded-full bg-rose-500 text-white shadow-lg shadow-rose-500/30"
                            :aria-label="
                                favorite ? 'Tirar dos favoritos' : 'Favoritar'
                            "
                            @click="emit('toggleFavorite')"
                        >
                            <Heart
                                class="size-6"
                                :class="{ 'fill-white': favorite }"
                            />
                        </button>

                        <div class="flex flex-col gap-1 pr-14">
                            <h2 class="text-xl font-semibold text-brand-ink">
                                {{ product.name }}
                            </h2>
                            <p class="flex items-center gap-2">
                                <span
                                    v-if="onSale"
                                    class="text-sm text-muted-foreground line-through"
                                    >{{ formatCents(product.priceCents) }}</span
                                >
                                <span class="font-semibold text-brand"
                                    ><template v-if="product.pizza"
                                        >a partir de </template
                                    >{{
                                        formatCents(product.effectivePriceCents)
                                    }}</span
                                >
                            </p>
                        </div>

                        <section v-if="product.description" class="mt-6">
                            <h3 class="mb-2 font-semibold text-brand-ink">
                                Descrição
                            </h3>
                            <DialogDescription
                                class="text-sm leading-relaxed text-muted-foreground"
                            >
                                {{ product.description }}
                            </DialogDescription>
                        </section>
                        <DialogDescription v-else class="sr-only"
                            >Escolha as opções e adicione ao
                            carrinho.</DialogDescription
                        >

                        <PizzaBuilder
                            v-if="product.pizza"
                            :pizza="product.pizza"
                            :options="options"
                            class="mt-6"
                        />

                        <section
                            v-for="group in product.optionGroups"
                            :key="group.id"
                            class="mt-6"
                        >
                            <div
                                class="mb-3 flex items-center justify-between gap-3"
                            >
                                <h3 class="font-semibold text-brand-ink">
                                    {{ group.name }}
                                </h3>
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                    :class="
                                        group.minChoices > 0 &&
                                        options.countIn(group) <
                                            group.minChoices
                                            ? 'bg-rose-50 text-rose-600'
                                            : 'bg-brand-surface text-muted-foreground'
                                    "
                                    >{{ options.ruleLabel(group) }}</span
                                >
                            </div>

                            <div
                                v-if="!group.allowsQuantity"
                                class="grid gap-2 sm:grid-cols-2"
                            >
                                <button
                                    v-for="option in group.options"
                                    :key="option.id"
                                    type="button"
                                    class="flex items-center gap-3 rounded-2xl border p-3 text-left transition-colors disabled:opacity-40"
                                    :class="
                                        options.isChosen(group, option.id)
                                            ? 'border-brand bg-brand/5'
                                            : 'border-border bg-white'
                                    "
                                    :disabled="
                                        options.isBlocked(group, option.id)
                                    "
                                    :aria-pressed="
                                        options.isChosen(group, option.id)
                                    "
                                    @click="options.toggle(group, option.id)"
                                >
                                    <span
                                        class="grid size-5 shrink-0 place-items-center border-2"
                                        :class="[
                                            options.isSingleChoice(group)
                                                ? 'rounded-full'
                                                : 'rounded-md',
                                            options.isChosen(group, option.id)
                                                ? 'border-brand bg-brand text-white'
                                                : 'border-slate-300',
                                        ]"
                                    >
                                        <Check
                                            v-if="
                                                options.isChosen(
                                                    group,
                                                    option.id,
                                                )
                                            "
                                            class="size-3"
                                        />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block text-sm text-brand-ink"
                                            >{{ option.name }}</span
                                        >
                                        <span
                                            v-if="option.description"
                                            class="block text-xs text-muted-foreground"
                                            >{{ option.description }}</span
                                        >
                                    </span>
                                    <span
                                        class="text-xs whitespace-nowrap text-brand"
                                        >{{
                                            options.optionPriceLabel(
                                                group,
                                                option.priceCents,
                                            )
                                        }}</span
                                    >
                                </button>
                            </div>

                            <div v-else class="flex flex-col gap-2">
                                <div
                                    v-for="option in group.options"
                                    :key="option.id"
                                    class="flex items-center gap-3 rounded-2xl border p-3"
                                    :class="
                                        options.isChosen(group, option.id)
                                            ? 'border-brand bg-brand/5'
                                            : 'border-border'
                                    "
                                >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block text-sm text-brand-ink"
                                            >{{ option.name }}</span
                                        >
                                        <span
                                            class="block text-xs text-brand"
                                            >{{
                                                options.optionPriceLabel(
                                                    group,
                                                    option.priceCents,
                                                )
                                            }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg bg-brand-surface text-brand-ink disabled:opacity-40"
                                        :aria-label="`Menos ${option.name}`"
                                        :disabled="
                                            !options.isChosen(group, option.id)
                                        "
                                        @click="
                                            options.changeOptionQuantity(
                                                group,
                                                option.id,
                                                -1,
                                            )
                                        "
                                    >
                                        <Minus class="size-4" />
                                    </button>
                                    <span
                                        class="w-5 text-center text-sm font-medium"
                                        >{{
                                            options.chosen(group)[option.id] ??
                                            0
                                        }}</span
                                    >
                                    <button
                                        type="button"
                                        class="grid size-8 place-items-center rounded-lg bg-brand text-white disabled:opacity-40"
                                        :aria-label="`Mais ${option.name}`"
                                        :disabled="
                                            options.countIn(group) >=
                                            group.maxChoices
                                        "
                                        @click="
                                            options.changeOptionQuantity(
                                                group,
                                                option.id,
                                                1,
                                            )
                                        "
                                    >
                                        <Plus class="size-4" />
                                    </button>
                                </div>
                            </div>
                        </section>

                        <section class="mt-6">
                            <h3 class="mb-2 font-semibold text-brand-ink">
                                Alguma observação?
                            </h3>
                            <TextareaField
                                v-model="notes"
                                maxlength="200"
                                placeholder="Ex.: sem cebola, ponto da carne"
                                class="min-h-20 rounded-2xl border-0 bg-brand-surface"
                                aria-label="Observação do item"
                            />
                        </section>
                    </div>
                </div>

                <div
                    class="flex items-center gap-3 border-t bg-white px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]"
                >
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="grid size-9 place-items-center rounded-xl bg-brand-surface text-brand-ink disabled:opacity-40"
                            aria-label="Diminuir quantidade"
                            :disabled="quantity <= 1"
                            @click="quantity--"
                        >
                            <Minus class="size-4" />
                        </button>
                        <span
                            class="w-5 text-center font-semibold text-brand-ink"
                            >{{ quantity }}</span
                        >
                        <button
                            type="button"
                            class="grid size-9 place-items-center rounded-xl bg-brand text-white disabled:opacity-40"
                            aria-label="Aumentar quantidade"
                            :disabled="quantity >= 99"
                            @click="quantity++"
                        >
                            <Plus class="size-4" />
                        </button>
                    </div>
                    <button
                        type="button"
                        class="line-clamp-2 h-14 min-w-0 flex-1 rounded-2xl bg-linear-to-r from-brand to-brand-accent px-4 text-sm leading-tight font-medium text-white shadow-lg shadow-brand/25 transition-opacity disabled:opacity-50"
                        :disabled="
                            !canOrder ||
                            product.soldOut ||
                            missingGroups.length > 0
                        "
                        @click="add"
                    >
                        {{ buttonLabel }}
                    </button>
                    <span
                        class="text-xl font-bold whitespace-nowrap text-brand-ink"
                        >{{ formatCents(totalCents) }}</span
                    >
                </div>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
