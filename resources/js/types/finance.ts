export type FinanceKind = 'payable' | 'receivable';

export type InstallmentStatus = 'open' | 'overdue' | 'paid' | 'canceled';

export type InstallmentRow = {
    id: number;
    titleId: number;
    type: FinanceKind;
    description: string;
    number: number;
    count: number;
    party: string | null;
    category: string | null;
    documentNumber: string | null;
    dueOn: string;
    amountCents: number;
    paidOn: string | null;
    paidCents: number | null;
    paymentMethod: string | null;
    automatic: boolean;
    throughCash: boolean;
    notes: string | null;
    status: InstallmentStatus;
    statusLabel: string;
    daysLate: number;
};

export type Option = { value: string; label: string };

export type NamedOption = { id: number; name: string };

export type CountAndCents = { count: number; cents: number };
