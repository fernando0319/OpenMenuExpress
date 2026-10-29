import type { PricingMode } from '@/types/menu';

type Selected = { priceCents: number; quantity: number };

/**
 * Preview of what a group adds to one unit, mirroring App\Services\Pricing\SelectedGroup.
 * The server recomputes it; keep both in sync.
 */
export function groupPriceCents(
    mode: PricingMode,
    selected: Selected[],
): number {
    if (selected.length === 0) {
        return 0;
    }

    if (mode === 'highest') {
        return Math.max(...selected.map((option) => option.priceCents));
    }

    const total = selected.reduce(
        (sum, option) => sum + option.priceCents * option.quantity,
        0,
    );

    if (mode === 'sum') {
        return total;
    }

    const count = selected.reduce((sum, option) => sum + option.quantity, 0);

    return Math.floor((2 * total + count) / (2 * count));
}
