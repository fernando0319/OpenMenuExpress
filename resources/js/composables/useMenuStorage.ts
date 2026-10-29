import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Small per-store memories kept in this browser only: favorite products and recent orders.
 * Losing them is harmless; nothing here is trusted by the server.
 */
function readList<T>(key: string): T[] {
    try {
        const parsed: unknown = JSON.parse(
            window.localStorage.getItem(key) ?? '[]',
        );

        return Array.isArray(parsed) ? (parsed as T[]) : [];
    } catch {
        return [];
    }
}

function writeList(key: string, value: unknown[]): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Private mode or full storage: it works until the page closes.
    }
}

export function useFavorites(storeSlug: string) {
    const key = `menu-express:favorites:${storeSlug}`;
    const ids = ref<number[]>(readList<number>(key));

    watch(ids, (value) => writeList(key, value), { deep: true });

    function isFavorite(productId: number): boolean {
        return ids.value.includes(productId);
    }

    function toggleFavorite(productId: number): void {
        ids.value = isFavorite(productId)
            ? ids.value.filter((id) => id !== productId)
            : [...ids.value, productId];
    }

    return { ids, isFavorite, toggleFavorite };
}

export type RecentOrder = {
    token: string;
    number: number;
    totalCents: number;
    placedAt: string;
};

export function useRecentOrders(storeSlug: string) {
    const key = `menu-express:orders:${storeSlug}`;
    const orders = ref<RecentOrder[]>(readList<RecentOrder>(key));

    function remember(order: RecentOrder): void {
        orders.value = [
            order,
            ...orders.value.filter((item) => item.token !== order.token),
        ].slice(0, 10);
        writeList(key, orders.value);
    }

    return { orders, remember };
}

/** CSS variables a store's colors override (see the @theme block in app.css). */
const BRAND_VARIABLES = [
    '--color-brand',
    '--color-brand-accent',
    '--primary',
    '--ring',
] as const;

/**
 * Applies the customer menu look to <html> while a menu page is open, so dialogs
 * (rendered on <body>) get it too, and dark mode never applies to the customer.
 * The store's colors replace the default purple and teal.
 */
export function useMenuTheme(colors?: {
    primaryColor: string;
    accentColor: string;
}): void {
    // Applied right away (not on mount) so the default purple never flashes first.
    if (typeof document !== 'undefined') {
        const root = document.documentElement;
        root.classList.add('menu-theme');

        if (colors) {
            root.style.setProperty('--color-brand', colors.primaryColor);
            root.style.setProperty('--color-brand-accent', colors.accentColor);
            root.style.setProperty('--primary', colors.primaryColor);
            root.style.setProperty('--ring', colors.primaryColor);
        }
    }

    onBeforeUnmount(() => {
        const root = document.documentElement;
        root.classList.remove('menu-theme');
        BRAND_VARIABLES.forEach((variable) =>
            root.style.removeProperty(variable),
        );
    });
}
