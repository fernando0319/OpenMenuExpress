<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { toRef } from 'vue';
import PizzaBuilder from '@/components/menu/PizzaBuilder.vue';
import TextareaField from '@/components/TextareaField.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { useProductOptions } from '@/composables/useProductOptions';
import { formatCents } from '@/lib/money';
import type { CartItem, MenuProduct } from '@/types/menu';

/**
 * Product options in a compact dialog, for the counter screens (PDV and tables).
 * The customer menu uses ProductSheet, with the same choices logic.
 */
const props = defineProps<{
    product: MenuProduct | null;
    canOrder: boolean;
}>();

const open = defineModel<boolean>('open', { required: true });

const emit = defineEmits<{
    add: [item: Omit<CartItem, 'key'>];
}>();

const options = useProductOptions(toRef(props, 'product'));
const {
    quantity,
    notes,
    chosen,
    countIn,
    isSingleChoice,
    toggle,
    changeOptionQuantity,
    ruleLabel,
    optionPriceLabel,
    missingGroups,
    unitCents,
    buildItem,
} = options;

function add(): void {
    const item = buildItem();

    if (item) {
        emit('add', item);
        open.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogScrollContent v-if="product" class="sm:max-w-lg">
            <img
                v-if="product.imageUrl"
                :src="product.imageUrl"
                :alt="product.name"
                class="aspect-square max-h-72 w-full rounded-lg object-cover"
            />
            <DialogHeader>
                <DialogTitle>{{ product.name }}</DialogTitle>
                <DialogDescription v-if="product.description">
                    {{ product.description }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-6">
                <PizzaBuilder
                    v-if="product.pizza"
                    :pizza="product.pizza"
                    :options="options"
                />
                <fieldset
                    v-for="group in product.optionGroups"
                    :key="group.id"
                    class="flex flex-col gap-2"
                >
                    <legend
                        class="mb-2 flex w-full items-center justify-between"
                    >
                        <span class="font-medium">{{ group.name }}</span>
                        <Badge
                            :variant="
                                group.minChoices > 0 &&
                                countIn(group) < group.minChoices
                                    ? 'default'
                                    : 'secondary'
                            "
                        >
                            {{ ruleLabel(group) }}
                        </Badge>
                    </legend>

                    <div
                        v-for="option in group.options"
                        :key="option.id"
                        class="flex items-center justify-between gap-3 rounded-lg border p-3"
                        :class="{ 'border-primary': chosen(group)[option.id] }"
                    >
                        <label
                            v-if="!group.allowsQuantity"
                            class="flex flex-1 cursor-pointer items-center gap-3"
                        >
                            <input
                                :type="
                                    isSingleChoice(group) ? 'radio' : 'checkbox'
                                "
                                :name="`group-${group.id}`"
                                :checked="Boolean(chosen(group)[option.id])"
                                :disabled="
                                    !chosen(group)[option.id] &&
                                    !isSingleChoice(group) &&
                                    countIn(group) >= group.maxChoices
                                "
                                @click="toggle(group, option.id)"
                            />
                            <span class="flex-1">
                                <span class="block text-sm">{{
                                    option.name
                                }}</span>
                                <span
                                    v-if="option.description"
                                    class="block text-xs text-muted-foreground"
                                    >{{ option.description }}</span
                                >
                            </span>
                        </label>
                        <span v-else class="flex-1">
                            <span class="block text-sm">{{ option.name }}</span>
                            <span
                                v-if="option.description"
                                class="block text-xs text-muted-foreground"
                                >{{ option.description }}</span
                            >
                        </span>

                        <span
                            class="text-sm whitespace-nowrap text-muted-foreground"
                        >
                            {{ optionPriceLabel(group, option.priceCents) }}
                        </span>

                        <div
                            v-if="group.allowsQuantity"
                            class="flex items-center gap-2"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-7"
                                :aria-label="`Menos ${option.name}`"
                                :disabled="!chosen(group)[option.id]"
                                @click="
                                    changeOptionQuantity(group, option.id, -1)
                                "
                            >
                                <Minus />
                            </Button>
                            <span class="w-4 text-center text-sm">{{
                                chosen(group)[option.id] ?? 0
                            }}</span>
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                class="size-7"
                                :aria-label="`Mais ${option.name}`"
                                :disabled="countIn(group) >= group.maxChoices"
                                @click="
                                    changeOptionQuantity(group, option.id, 1)
                                "
                            >
                                <Plus />
                            </Button>
                        </div>
                    </div>
                </fieldset>

                <div class="grid gap-2">
                    <label for="item-notes" class="text-sm font-medium"
                        >Alguma observação?</label
                    >
                    <TextareaField
                        id="item-notes"
                        v-model="notes"
                        maxlength="200"
                        placeholder="Ex.: sem cebola, ponto da carne"
                        class="min-h-16"
                    />
                </div>

                <div class="flex items-center gap-3">
                    <div class="flex items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Diminuir quantidade"
                            :disabled="quantity <= 1"
                            @click="quantity--"
                        >
                            <Minus />
                        </Button>
                        <span class="w-6 text-center">{{ quantity }}</span>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Aumentar quantidade"
                            :disabled="quantity >= 99"
                            @click="quantity++"
                        >
                            <Plus />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        class="flex-1"
                        :disabled="
                            !canOrder ||
                            product.soldOut ||
                            missingGroups.length > 0
                        "
                        @click="add"
                    >
                        <template v-if="!canOrder">Loja fechada</template>
                        <template v-else-if="product.soldOut"
                            >Esgotado</template
                        >
                        <template v-else-if="missingGroups.length > 0">
                            Escolha {{ missingGroups[0].name.toLowerCase() }}
                        </template>
                        <template v-else>
                            Adicionar {{ formatCents(unitCents * quantity) }}
                        </template>
                    </Button>
                </div>
            </div>
        </DialogScrollContent>
    </Dialog>
</template>
