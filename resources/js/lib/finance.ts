import type { FinanceKind } from '@/types/finance';

export type Frequency = 'weekly' | 'biweekly' | 'monthly';

export type PlannedInstallment = { due_on: string; amount: string };

const dateFormat = new Intl.DateTimeFormat('pt-BR', { timeZone: 'UTC' });

/** "2026-10-20" → "20/10/2026", without shifting the day by the browser's timezone. */
export function formatDate(value: string | null): string {
    return value ? dateFormat.format(new Date(`${value}T00:00:00Z`)) : '—';
}

function toIso(date: Date): string {
    return date.toISOString().slice(0, 10);
}

/**
 * Due date of the installment at `index`, like App\Enums\FinanceFrequency::dueDate:
 * monthly keeps the day, or the month's last day when it is shorter (31/01 → 28/02).
 */
export function dueDate(
    first: string,
    index: number,
    frequency: Frequency,
): string {
    const [year, month, day] = first.split('-').map(Number);

    if (frequency !== 'monthly') {
        const date = new Date(Date.UTC(year, month - 1, day));
        date.setUTCDate(
            date.getUTCDate() + index * (frequency === 'weekly' ? 7 : 14),
        );

        return toIso(date);
    }

    const target = new Date(Date.UTC(year, month - 1 + index, 1));
    const lastDay = new Date(
        Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0),
    ).getUTCDate();
    target.setUTCDate(Math.min(day, lastDay));

    return toIso(target);
}

/**
 * Splits a total like App\Services\Finance\InstallmentPlan::split: the cents that do not divide
 * evenly go to the first installments, so the sum is exact.
 */
export function splitCents(totalCents: number, count: number): number[] {
    const safeCount = Math.max(1, Math.min(120, Math.floor(count)));
    const base = Math.floor(totalCents / safeCount);
    const remainder = totalCents - base * safeCount;

    return Array.from(
        { length: safeCount },
        (_, index) => base + (index < remainder ? 1 : 0),
    );
}

export function paidWord(type: FinanceKind): {
    verb: string;
    past: string;
    noun: string;
} {
    return type === 'payable'
        ? { verb: 'Pagar', past: 'Pago', noun: 'pagamento' }
        : { verb: 'Receber', past: 'Recebido', noun: 'recebimento' };
}
