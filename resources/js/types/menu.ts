export type PricingMode = 'sum' | 'highest' | 'average';

export type MenuOption = {
    id: number;
    name: string;
    description: string | null;
    priceCents: number;
};

export type MenuOptionGroup = {
    id: number;
    name: string;
    minChoices: number;
    maxChoices: number;
    pricingMode: PricingMode;
    allowsQuantity: boolean;
    options: MenuOption[];
};

export type PizzaFlavorOption = {
    id: number;
    name: string;
    description: string | null;
    imageUrl: string | null;
    priceCents: number;
};

export type PizzaFlavorGroupOption = {
    id: number;
    name: string;
    kind: 'savory' | 'sweet';
    priceCents: number;
    flavors: PizzaFlavorOption[];
};

/** A pizza size: the builder data (flavor groups priced for this size, crusts, rules). */
export type PizzaInfo = {
    slices: number | null;
    diameterCm: number | null;
    maxFlavors: number;
    fromCents: number;
    pricing: 'highest' | 'average';
    mixKinds: boolean;
    groups: PizzaFlavorGroupOption[];
    crusts: {
        id: number;
        name: string;
        description: string | null;
        priceCents: number;
    }[];
};

export type MenuProduct = {
    id: number;
    name: string;
    description: string | null;
    imageUrl: string | null;
    priceCents: number;
    effectivePriceCents: number;
    isFeatured: boolean;
    soldOut: boolean;
    optionGroups: MenuOptionGroup[];
    pizza?: PizzaInfo | null;
};

export type MenuCategory = {
    id: number;
    name: string;
    products: MenuProduct[];
};

export type MenuStore = {
    name: string;
    slug: string;
    description: string | null;
    whatsapp: string | null;
    isOpen: boolean;
    isPaused: boolean;
    /** "Hoje às 18:00" when closed, null when open or unknown. */
    nextOpening: string | null;
    acceptsDelivery: boolean;
    acceptsPickup: boolean;
    minimumOrderCents: number;
    address: string | null;
};

export type MenuBanner = {
    id: number;
    title: string;
    imageUrl: string;
    linkType: 'none' | 'product' | 'category' | 'coupon' | 'url';
    linkTarget: string | null;
};

export type CouponPreview = {
    code: string;
    label: string;
    discountCents: number;
    isFreeDelivery: boolean;
};

export type ScheduleSlot = {
    /** UTC ISO string sent back to the server as-is. */
    value: string;
    day: string;
    time: string;
};

export type WeeklyHours = {
    weekday: number;
    shifts: { opens: string; closes: string }[];
}[];

export type DeliveryZoneOption = {
    id: number;
    name: string;
    fee_cents: number;
};

export type PaymentMethodOption = {
    value: string;
    label: string;
};

export type RememberedCustomer = {
    name?: string;
    phone?: string;
    address?: {
        zip?: string | null;
        street?: string | null;
        number?: string | null;
        district?: string | null;
        complement?: string | null;
        reference?: string | null;
    };
    delivery_zone_id?: number | null;
};

export type CartOption = {
    optionId: number;
    groupId: number;
    groupName: string;
    name: string;
    quantity: number;
};

export type CartItem = {
    key: string;
    productId: number;
    name: string;
    /** Photo for the cart card; older carts saved in the browser may not have it. */
    imageUrl?: string | null;
    quantity: number;
    notes: string;
    options: CartOption[];
    /** Preview only: the server recomputes every price. */
    unitCents: number;
    /** Pizza sizes: flavors in the order chosen and the crust. */
    pizza?: {
        flavorIds: number[];
        crustId: number | null;
        flavorLabels: string[];
        crustLabel: string | null;
    } | null;
};

export type MenuBranding = {
    logoUrl: string | null;
    coverUrl: string | null;
    primaryColor: string;
    accentColor: string;
    /** Active theme; only "default" exists today. */
    theme: string;
};
