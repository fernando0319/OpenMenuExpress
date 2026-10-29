import { computed, ref, watch } from 'vue';
import type { CartItem } from '@/types/menu';

/** Same product, same choices and same notes: one line in the cart. */
function lineSignature(item: Omit<CartItem, 'key'>): string {
    const options = item.options
        .map((option) => `${option.optionId}x${option.quantity}`)
        .sort()
        .join(',');

    const pizza = item.pizza
        ? `${item.pizza.flavorIds.join('/')}+${item.pizza.crustId ?? ''}`
        : '';

    return `${item.productId}|${options}|${pizza}|${item.notes.trim()}`;
}

/**
 * Cart kept in this browser only (per store). Losing it is harmless:
 * the order itself is built and priced on the server.
 */
export function useCart(storeSlug: string) {
    const storageKey = `menu-express:cart:${storeSlug}`;
    const items = ref<CartItem[]>(load());

    function load(): CartItem[] {
        try {
            const raw = window.localStorage.getItem(storageKey);
            const parsed: unknown = raw ? JSON.parse(raw) : [];

            return Array.isArray(parsed) ? (parsed as CartItem[]) : [];
        } catch {
            return [];
        }
    }

    watch(
        items,
        (value) => {
            try {
                window.localStorage.setItem(storageKey, JSON.stringify(value));
            } catch {
                // Private mode or full storage: the cart still works until the page closes.
            }
        },
        { deep: true },
    );

    const count = computed(() =>
        items.value.reduce((sum, item) => sum + item.quantity, 0),
    );

    const subtotalCents = computed(() =>
        items.value.reduce(
            (sum, item) => sum + item.unitCents * item.quantity,
            0,
        ),
    );

    function add(item: Omit<CartItem, 'key'>): void {
        // The same product with the same choices and notes stacks on its existing line.
        const signature = lineSignature(item);
        const same = items.value.find(
            (entry) => lineSignature(entry) === signature,
        );

        if (same) {
            same.quantity = Math.min(same.quantity + item.quantity, 99);

            return;
        }

        items.value.push({
            ...item,
            key: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        });
    }

    function setQuantity(key: string, quantity: number): void {
        const item = items.value.find((entry) => entry.key === key);

        if (!item) {
            return;
        }

        if (quantity <= 0) {
            remove(key);

            return;
        }

        item.quantity = Math.min(quantity, 99);
    }

    function remove(key: string): void {
        items.value = items.value.filter((entry) => entry.key !== key);
    }

    function clear(): void {
        items.value = [];
    }

    return { items, count, subtotalCents, add, setQuantity, remove, clear };
}
