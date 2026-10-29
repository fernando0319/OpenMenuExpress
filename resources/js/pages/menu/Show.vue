<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Clock, MapPin, MessageCircle, Search, Share2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import BannerCarousel from '@/components/menu/BannerCarousel.vue';
import BottomSheet from '@/components/menu/BottomSheet.vue';
import CartSheet from '@/components/menu/CartSheet.vue';
import CategoryTiles from '@/components/menu/CategoryTiles.vue';
import MenuBottomNav from '@/components/menu/MenuBottomNav.vue';
import type { MenuTab } from '@/components/menu/MenuBottomNav.vue';
import MenuProductCard from '@/components/menu/MenuProductCard.vue';
import ProductSheet from '@/components/menu/ProductSheet.vue';
import { Toaster } from '@/components/ui/sonner';
import { useCart } from '@/composables/useCart';
import {
    useFavorites,
    useMenuTheme,
    useRecentOrders,
} from '@/composables/useMenuStorage';
import { formatCents } from '@/lib/money';
import { show as orderShow } from '@/routes/menu/orders';
import type {
    DeliveryZoneOption,
    MenuBanner,
    MenuBranding,
    MenuCategory,
    MenuProduct,
    MenuStore,
    PaymentMethodOption,
    RememberedCustomer,
    ScheduleSlot,
    WeeklyHours,
} from '@/types/menu';

const props = defineProps<{
    store: MenuStore;
    hours: WeeklyHours;
    slots: ScheduleSlot[];
    banners: MenuBanner[];
    categories: MenuCategory[];
    deliveryZones: DeliveryZoneOption[];
    paymentMethods: PaymentMethodOption[];
    onlinePaymentMethods: PaymentMethodOption[];
    customer: RememberedCustomer | null;
    branding: MenuBranding;
}>();

useMenuTheme(props.branding);

const cart = useCart(props.store.slug);
const favorites = useFavorites(props.store.slug);
const recentOrders = useRecentOrders(props.store.slug);

const tab = ref<MenuTab>('home');
const search = ref('');
const activeCategory = ref<number | null>(null);
const selectedProduct = ref<MenuProduct | null>(null);
const productOpen = ref(false);
const cartOpen = ref(false);
const infoOpen = ref(false);
const ordersOpen = ref(false);
// A coupon picked from a banner is applied when the customer opens the cart.
const bannerCoupon = ref<string | null>(null);

// Closed stores still take orders when there are times to book.
const canOrder = computed(
    () =>
        (props.store.isOpen || props.slots.length > 0) &&
        (props.store.acceptsPickup || props.store.acceptsDelivery),
);

const allProducts = computed(() =>
    props.categories.flatMap((category) => category.products),
);
const popular = computed(() =>
    allProducts.value.filter((product) => product.isFeatured).slice(0, 10),
);

function normalize(text: string): string {
    return text
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

const searchResults = computed(() => {
    const term = normalize(search.value.trim());

    if (term === '') {
        return [];
    }

    return allProducts.value.filter(
        (product) =>
            normalize(product.name).includes(term) ||
            normalize(product.description ?? '').includes(term),
    );
});

const favoriteProducts = computed(() =>
    allProducts.value.filter((product) => favorites.isFavorite(product.id)),
);

const statusText = computed(() => {
    if (props.store.isOpen) {
        return 'Aberto agora';
    }

    if (props.store.isPaused) {
        return 'Fechado no momento';
    }

    return props.store.nextOpening
        ? `Fechado · abre ${props.store.nextOpening.charAt(0).toLowerCase()}${props.store.nextOpening.slice(1)}`
        : 'Fechado';
});

const weekdayNames = [
    'Domingo',
    'Segunda',
    'Terça',
    'Quarta',
    'Quinta',
    'Sexta',
    'Sábado',
];
const hasHours = computed(() =>
    props.hours.some((day) => day.shifts.length > 0),
);

function openProduct(product: MenuProduct): void {
    selectedProduct.value = product;
    productOpen.value = true;
}

function addToCart(item: Parameters<typeof cart.add>[0]): void {
    cart.add(item);
    toast.success(`${item.name} no carrinho.`);
}

function openCart(): void {
    toast.dismiss();
    cartOpen.value = true;
}

function goToCategory(categoryId: number): void {
    tab.value = 'home';
    search.value = '';
    activeCategory.value = categoryId;
    requestAnimationFrame(() =>
        document
            .getElementById(`category-${categoryId}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
    );
}

function goHome(): void {
    tab.value = 'home';
    activeCategory.value = null;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function showFavorites(): void {
    tab.value = 'favorites';
    search.value = '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function onBanner(banner: MenuBanner): void {
    const target = banner.linkTarget ?? '';

    switch (banner.linkType) {
        case 'product': {
            const product = allProducts.value.find(
                (item) => String(item.id) === target,
            );

            if (product) {
                openProduct(product);
            }

            break;
        }
        case 'category':
            goToCategory(Number(target));
            break;
        case 'coupon':
            bannerCoupon.value = target;
            toast.success(`Cupom ${target} será aplicado no carrinho.`);
            break;
        case 'url':
            window.open(target, '_blank', 'noopener');
            break;
    }
}

async function share(): Promise<void> {
    const data = { title: props.store.name, url: window.location.href };

    try {
        if (navigator.share) {
            await navigator.share(data);
        } else {
            await navigator.clipboard.writeText(data.url);
            toast.success('Link do cardápio copiado.');
        }
    } catch {
        // The customer closed the share sheet.
    }
}

const dateFormat = new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
});
</script>

<template>
    <Head :title="store.name" />
    <!-- Above the bottom bar, so it never covers the cart header. -->
    <Toaster
        position="bottom-center"
        :duration="2000"
        :offset="96"
        :mobile-offset="96"
    />

    <div class="min-h-svh bg-brand-surface pb-32 font-display text-brand-ink">
        <section class="relative">
            <div class="h-36 overflow-hidden rounded-b-[2rem] sm:h-52">
                <img
                    v-if="branding.coverUrl"
                    :src="branding.coverUrl"
                    :alt="`Capa de ${store.name}`"
                    class="size-full object-cover"
                />
                <div
                    v-else
                    class="size-full bg-linear-to-br from-brand to-brand-accent"
                />
            </div>
            <div
                class="relative mx-auto -mt-10 flex max-w-5xl items-end gap-3 px-4"
            >
                <span
                    class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-3xl bg-white text-2xl font-bold text-brand shadow-soft ring-4 ring-white"
                >
                    <img
                        v-if="branding.logoUrl"
                        :src="branding.logoUrl"
                        :alt="`Logo de ${store.name}`"
                        class="size-full object-cover"
                    />
                    <span
                        v-else
                        class="grid size-full place-items-center bg-linear-to-br from-brand to-brand-accent text-white"
                        >{{ store.name.charAt(0).toUpperCase() }}</span
                    >
                </span>
                <div class="min-w-0 flex-1 pb-1">
                    <h1
                        class="line-clamp-2 text-lg leading-tight font-semibold text-brand"
                    >
                        {{ store.name }}
                    </h1>
                    <p
                        class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="
                                store.isOpen ? 'bg-emerald-500' : 'bg-rose-500'
                            "
                        />
                        <span class="truncate">{{ statusText }}</span>
                    </p>
                </div>
                <div class="flex shrink-0 pb-1">
                    <button
                        type="button"
                        class="grid size-10 place-items-center rounded-full text-brand-accent"
                        aria-label="Horários e informações"
                        @click="infoOpen = true"
                    >
                        <Clock class="size-5" />
                    </button>
                    <button
                        type="button"
                        class="grid size-10 place-items-center rounded-full text-rose-400"
                        aria-label="Compartilhar cardápio"
                        @click="share"
                    >
                        <Share2 class="size-5" />
                    </button>
                </div>
            </div>
        </section>

        <header
            class="sticky top-0 z-30 bg-brand-surface/90 px-4 pt-4 pb-3 backdrop-blur"
        >
            <label class="relative mx-auto block max-w-5xl">
                <Search
                    class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-brand"
                />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Buscar no cardápio"
                    class="h-11 w-full rounded-2xl border border-border bg-white pr-10 pl-11 text-sm shadow-soft outline-none placeholder:text-muted-foreground focus:border-brand"
                    aria-label="Buscar no cardápio"
                />
                <button
                    v-if="search"
                    type="button"
                    class="absolute top-1/2 right-3 -translate-y-1/2 text-muted-foreground"
                    aria-label="Limpar busca"
                    @click="search = ''"
                >
                    <X class="size-4" />
                </button>
            </label>
        </header>

        <main class="mx-auto flex max-w-5xl flex-col gap-7 px-4 pt-3">
            <p
                v-if="!store.isOpen"
                class="rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 ring-1 ring-amber-200"
            >
                <template v-if="store.isPaused"
                    >A loja está fechada para pedidos no momento.</template
                >
                <template v-else>{{ statusText }}.</template>
                <template v-if="slots.length > 0">
                    Você já pode fazer o pedido e agendar o horário.</template
                >
            </p>

            <!-- Search -->
            <section v-if="search.trim() !== ''">
                <h2 class="mb-3 text-lg font-semibold">
                    Resultados para "{{ search.trim() }}"
                </h2>
                <p
                    v-if="searchResults.length === 0"
                    class="rounded-3xl bg-white p-8 text-center text-sm text-muted-foreground shadow-soft"
                >
                    Nenhum produto encontrado.
                </p>
                <div v-else class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <MenuProductCard
                        v-for="product in searchResults"
                        :key="product.id"
                        :product="product"
                        :favorite="favorites.isFavorite(product.id)"
                        @open="openProduct(product)"
                        @toggle-favorite="favorites.toggleFavorite(product.id)"
                    />
                </div>
            </section>

            <!-- Favorites -->
            <section v-else-if="tab === 'favorites'">
                <h2 class="mb-3 text-lg font-semibold">Seus favoritos</h2>
                <p
                    v-if="favoriteProducts.length === 0"
                    class="rounded-3xl bg-white p-8 text-center text-sm text-muted-foreground shadow-soft"
                >
                    Toque no coração dos produtos para guardá-los aqui.
                </p>
                <div v-else class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                    <MenuProductCard
                        v-for="product in favoriteProducts"
                        :key="product.id"
                        :product="product"
                        :favorite="true"
                        @open="openProduct(product)"
                        @toggle-favorite="favorites.toggleFavorite(product.id)"
                    />
                </div>
            </section>

            <template v-else>
                <BannerCarousel :banners="banners" @select="onBanner" />

                <section v-if="categories.length > 0">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-lg font-semibold">
                            Explorar categorias
                        </h2>
                        <button
                            v-if="activeCategory !== null"
                            type="button"
                            class="text-sm text-muted-foreground"
                            @click="goHome"
                        >
                            Ver todos
                        </button>
                    </div>
                    <CategoryTiles
                        :categories="categories"
                        :active-id="activeCategory"
                        @select="goToCategory"
                    />
                </section>

                <section v-if="popular.length > 0">
                    <h2 class="mb-3 text-lg font-semibold">
                        Produtos populares
                    </h2>
                    <div
                        class="-mx-4 flex snap-x scroll-px-4 [scrollbar-width:none] gap-4 overflow-x-auto px-4 pt-1 pb-4 [&::-webkit-scrollbar]:hidden"
                    >
                        <MenuProductCard
                            v-for="product in popular"
                            :key="product.id"
                            variant="tile"
                            :product="product"
                            :favorite="favorites.isFavorite(product.id)"
                            @open="openProduct(product)"
                            @toggle-favorite="
                                favorites.toggleFavorite(product.id)
                            "
                        />
                    </div>
                </section>

                <p
                    v-if="categories.length === 0"
                    class="rounded-3xl bg-white p-10 text-center text-sm text-muted-foreground shadow-soft"
                >
                    O cardápio ainda está sendo montado. Volte em breve!
                </p>

                <section
                    v-for="category in categories"
                    :id="`category-${category.id}`"
                    :key="category.id"
                    class="scroll-mt-40"
                >
                    <div class="mb-3 flex items-baseline justify-between">
                        <h2 class="text-lg font-semibold">
                            {{ category.name }}
                        </h2>
                        <span class="text-xs text-muted-foreground"
                            >{{ category.products.length }}
                            {{
                                category.products.length === 1
                                    ? 'item'
                                    : 'itens'
                            }}</span
                        >
                    </div>
                    <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                        <MenuProductCard
                            v-for="product in category.products"
                            :key="product.id"
                            :product="product"
                            :favorite="favorites.isFavorite(product.id)"
                            @open="openProduct(product)"
                            @toggle-favorite="
                                favorites.toggleFavorite(product.id)
                            "
                        />
                    </div>
                </section>
            </template>

            <footer
                class="mt-4 flex flex-col items-center gap-1 text-center text-xs text-muted-foreground"
            >
                <p class="font-medium text-brand-ink">{{ store.name }}</p>
                <p v-if="store.address">{{ store.address }}</p>
                <p>Feito com OpenMenuExpress</p>
            </footer>
        </main>
    </div>

    <MenuBottomNav
        :tab="tab"
        :cart-count="cart.count.value"
        @home="goHome"
        @info="infoOpen = true"
        @cart="openCart"
        @favorites="showFavorites"
        @orders="ordersOpen = true"
    />

    <ProductSheet
        v-model:open="productOpen"
        :product="selectedProduct"
        :can-order="canOrder"
        :favorite="
            selectedProduct ? favorites.isFavorite(selectedProduct.id) : false
        "
        @add="addToCart"
        @toggle-favorite="
            selectedProduct && favorites.toggleFavorite(selectedProduct.id)
        "
    />

    <CartSheet
        v-model:open="cartOpen"
        :store="store"
        :items="cart.items.value"
        :subtotal-cents="cart.subtotalCents.value"
        :delivery-zones="deliveryZones"
        :payment-methods="paymentMethods"
        :online-payment-methods="onlinePaymentMethods"
        :customer="customer"
        :slots="slots"
        :banner-coupon="bannerCoupon"
        @set-quantity="cart.setQuantity"
        @remove="cart.remove"
        @placed="cart.clear"
    />

    <BottomSheet
        v-model:open="infoOpen"
        :title="store.name"
        :description="statusText"
    >
        <div class="flex flex-col gap-5 text-sm">
            <p v-if="store.description" class="text-muted-foreground">
                {{ store.description }}
            </p>

            <div v-if="store.address" class="flex gap-3">
                <MapPin class="mt-0.5 size-4 shrink-0 text-brand" />
                <span>{{ store.address }}</span>
            </div>

            <dl class="grid gap-1 rounded-2xl bg-brand-surface p-4">
                <div
                    v-if="store.minimumOrderCents > 0"
                    class="flex justify-between"
                >
                    <dt class="text-muted-foreground">Pedido mínimo</dt>
                    <dd class="font-medium">
                        {{ formatCents(store.minimumOrderCents) }}
                    </dd>
                </div>
                <div class="flex justify-between">
                    <dt class="text-muted-foreground">Formas de entrega</dt>
                    <dd class="font-medium">
                        {{
                            [
                                store.acceptsDelivery ? 'Entrega' : null,
                                store.acceptsPickup ? 'Retirada' : null,
                            ]
                                .filter(Boolean)
                                .join(' e ') || '—'
                        }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-muted-foreground">Pagamento</dt>
                    <dd class="text-right font-medium">
                        {{
                            [...onlinePaymentMethods, ...paymentMethods]
                                .map((method) => method.label)
                                .join(', ')
                        }}
                    </dd>
                </div>
            </dl>

            <div v-if="hasHours">
                <h3 class="mb-2 font-semibold text-brand-ink">Horários</h3>
                <dl class="grid gap-1.5">
                    <div
                        v-for="day in hours"
                        :key="day.weekday"
                        class="flex justify-between gap-4"
                    >
                        <dt>{{ weekdayNames[day.weekday] }}</dt>
                        <dd class="text-right text-muted-foreground">
                            <template v-if="day.shifts.length === 0"
                                >Fechado</template
                            >
                            <span
                                v-for="shift in day.shifts"
                                v-else
                                :key="shift.opens"
                                class="block"
                                >{{ shift.opens }} às {{ shift.closes }}</span
                            >
                        </dd>
                    </div>
                </dl>
            </div>

            <a
                v-if="store.whatsapp"
                :href="`https://wa.me/${store.whatsapp}`"
                target="_blank"
                rel="noopener"
                class="flex h-12 items-center justify-center gap-2 rounded-2xl bg-emerald-500 font-medium text-white"
            >
                <MessageCircle class="size-5" />
                Falar no WhatsApp
            </a>
        </div>
    </BottomSheet>

    <BottomSheet
        v-model:open="ordersOpen"
        title="Meus pedidos"
        description="Pedidos feitos neste aparelho."
    >
        <p
            v-if="recentOrders.orders.value.length === 0"
            class="py-6 text-center text-sm text-muted-foreground"
        >
            Você ainda não fez pedidos por aqui.
        </p>
        <ul v-else class="flex flex-col gap-2">
            <li v-for="order in recentOrders.orders.value" :key="order.token">
                <Link
                    :href="orderShow([store.slug, order.token])"
                    class="flex items-center justify-between gap-3 rounded-2xl bg-brand-surface p-4 text-sm"
                >
                    <span>
                        <span class="block font-semibold text-brand-ink"
                            >Pedido nº {{ order.number }}</span
                        >
                        <span class="text-xs text-muted-foreground">{{
                            dateFormat.format(new Date(order.placedAt))
                        }}</span>
                    </span>
                    <span class="font-semibold text-brand">{{
                        formatCents(order.totalCents)
                    }}</span>
                </Link>
            </li>
        </ul>
    </BottomSheet>
</template>
