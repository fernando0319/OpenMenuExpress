export type CurrentStore = {
    id: number;
    name: string;
    slug: string;
    role: 'owner' | 'manager' | 'attendant' | 'waiter' | 'courier' | null;
    canManageCatalog: boolean;
    canManageStore: boolean;
    canManageFinance: boolean;
    canOperatePos: boolean;
    canServeTables: boolean;
    canViewOrders: boolean;
    hasDeliveries: boolean;
    /** The store color adapted to each panel mode (see StoreBranding::panelPalette). */
    subscription: {
        enabled: boolean;
        status:
            | 'trial'
            | 'active'
            | 'free'
            | 'usage'
            | 'grace'
            | 'expired'
            | 'complimentary'
            | 'disabled';
        reason: 'ended' | 'over_limit' | 'invoice' | null;
        label: string;
        planName: string | null;
        endsAt: string | null;
        daysLeft: number | null;
        graceDaysLeft: number | null;
        warn: boolean;
        blocked: boolean;
        modules: string[];
        /** This month against the free plan's limit (only on a free plan). */
        free: {
            limitCents: number;
            salesCents: number;
            overLimit: boolean;
        } | null;
        /** Plans charged by a percentage of the online sales. */
        usage: {
            summary: string | null;
            salesCents: number;
            estimateCents: number;
            freeUpToCents: number | null;
            openInvoice: { period: string; amountCents: number } | null;
        } | null;
    };
    palette: {
        light: { primary: string; foreground: string };
        dark: { primary: string; foreground: string };
        custom: boolean;
    };
    otherStores: { id: number; name: string }[];
};

export type CategoryRow = {
    id: number;
    name: string;
    is_active: boolean;
    sort_order: number;
    products_count: number;
};

export type SelectOption = {
    id: number;
    name: string;
};

export type PricingModeOption = {
    value: 'sum' | 'highest' | 'average';
    label: string;
};

export type OptionGroupRow = {
    id: number;
    name: string;
    min_choices: number;
    max_choices: number;
    pricing_mode_label: string;
    is_active: boolean;
    options_count: number;
    products_count: number;
};

export type OptionGroupChoice = {
    id: number;
    name: string;
    min_choices: number;
    max_choices: number;
    options_count: number;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};
