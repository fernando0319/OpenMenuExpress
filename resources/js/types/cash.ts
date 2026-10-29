export type CashSummary = {
    openingCents: number;
    byMethod: { method: string; label: string; cents: number }[];
    salesCents: number;
    refundsCents: number;
    suppliesCents: number;
    withdrawalsCents: number;
    netSalesCents: number;
    ordersCount: number;
    expectedCashCents: number;
};

export type CashMovementRow = {
    id: number;
    type: 'sale' | 'refund' | 'supply' | 'withdrawal';
    typeLabel: string;
    method: string | null;
    signedCents: number;
    description: string | null;
    orderId: number | null;
    by: string | null;
    at: string | null;
};

export type CashSessionDetail = {
    id: number;
    isOpen: boolean;
    openedAt: string;
    openedBy: string | null;
    closedAt: string | null;
    closedBy: string | null;
    expectedCashCents: number | null;
    countedCashCents: number | null;
    differenceCents: number | null;
    notes: string | null;
    summary: CashSummary;
    movements: CashMovementRow[];
};

export type CashSessionRow = {
    id: number;
    openedAt: string;
    closedAt: string | null;
    closedBy: string | null;
    expectedCashCents: number | null;
    countedCashCents: number | null;
    differenceCents: number | null;
};
