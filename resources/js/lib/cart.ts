import type { CartItem } from '@/types/menu';

/**
 * The choices of a cart line as one short text: pizza flavors ("½ Calabresa / ½ Portuguesa"),
 * crust, then the other options.
 */
export function cartItemDetails(item: Omit<CartItem, 'key'>): string {
    const parts: string[] = [];

    if (item.pizza) {
        parts.push(item.pizza.flavorLabels.join(' / '));

        if (item.pizza.crustLabel) {
            parts.push(`Borda ${item.pizza.crustLabel}`);
        }
    }

    parts.push(
        ...item.options.map((option) =>
            option.quantity > 1
                ? `${option.quantity}× ${option.name}`
                : option.name,
        ),
    );

    return parts.filter((part) => part !== '').join(', ');
}

/** What the server expects for one cart line. */
export function cartItemPayload(item: Omit<CartItem, 'key'>) {
    return {
        product_id: item.productId,
        quantity: item.quantity,
        notes: item.notes,
        options: item.options.map((option) => ({
            option_id: option.optionId,
            quantity: option.quantity,
        })),
        pizza: item.pizza
            ? { flavor_ids: item.pizza.flavorIds, crust_id: item.pizza.crustId }
            : null,
    };
}
