export type TableTile = {
    id: number;
    name: string;
    seats: number | null;
    status: 'free' | 'open' | 'closing' | 'joined';
    joinedTo: { id: number; name: string } | null;
    joinedNames: string[];
    people: number | null;
    customerName: string | null;
    openedAt: string | null;
    consumedCents: number;
};

export type TabBill = {
    subtotalCents: number;
    serviceFeePercent: number;
    serviceFeeCents: number;
    discountCents: number;
    totalCents: number;
};

export type TabRound = {
    id: number;
    number: number;
    status: string;
    statusLabel: string;
    editable: boolean;
    placedAt: string;
    totalCents: number;
    notes: string | null;
    items: {
        id: number;
        name: string;
        quantity: number;
        totalCents: number;
        notes: string | null;
        options: string;
    }[];
};

export type TabDetail = {
    id: number;
    status: 'open' | 'closing';
    statusLabel: string;
    people: number | null;
    customerName: string | null;
    openedAt: string;
    openedBy: string | null;
    joinedTables: { id: number; name: string }[];
    bill: TabBill;
    rounds: TabRound[];
};
