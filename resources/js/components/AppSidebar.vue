<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Armchair,
    BadgeCheck,
    Bike,
    MapPinned,
    Palette,
    ChartColumn,
    ShieldCheck,
    Ticket,
    Layers,
    FileSpreadsheet,
    Clock,
    CreditCard,
    QrCode,
    ExternalLink,
    FolderGit2,
    MonitorDown,
    GalleryHorizontal,
    LayoutGrid,
    ListChecks,
    MessageCircle,
    Package,
    Pizza,
    Printer,
    ReceiptText,
    ShoppingCart,
    Tags,
    TicketPercent,
    UsersRound,
    Truck,
    Wallet,
    Landmark,
    HandCoins,
    FileText,
    Factory,
    Route,
    Navigation,
    Store,
    BookOpenCheck,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import NavUser from '@/components/NavUser.vue';
import { insideDesktopApp } from '@/lib/desktop';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { map as couriersMap } from '@/routes/couriers';
import { index as deliveriesIndex } from '@/routes/deliveries';
import { dashboard as adminDashboard } from '@/routes/admin';
import { index as adminInvoices } from '@/routes/admin/invoices';
import { index as adminNetwork } from '@/routes/admin/network';
import { index as adminPlans } from '@/routes/admin/plans';
import { index as adminVouchers } from '@/routes/admin/vouchers';
import { show as billingShow } from '@/routes/billing';
import { index as cashIndex } from '@/routes/cash';
import { index as categoriesIndex } from '@/routes/catalog/categories';
import { index as optionGroupsIndex } from '@/routes/catalog/option-groups';
import { index as pizzasIndex } from '@/routes/catalog/pizzas';
import { index as productsIndex } from '@/routes/catalog/products';
import { index as bannersIndex } from '@/routes/marketing/banners';
import { index as couponsIndex } from '@/routes/marketing/coupons';
import {
    overview as financeOverview,
    payables as financePayables,
    receivables as financeReceivables,
} from '@/routes/finance';
import { index as suppliersIndex } from '@/routes/finance/suppliers';
import { show as menuShow } from '@/routes/menu';
import { switchMethod as switchStore } from '@/routes/stores';
import { index as ordersIndex } from '@/routes/orders';
import { index as reportsIndex } from '@/routes/reports';
import { index as ledgerIndex } from '@/routes/ledger';
import { show as posShow } from '@/routes/pos';
import { edit as appearanceEdit } from '@/routes/store-settings/appearance';
import { edit as contactEdit } from '@/routes/store-settings/contact';
import { edit as courierNetworkEdit } from '@/routes/store-settings/courier-network';
import { edit as deliveryEdit } from '@/routes/store-settings/delivery';
import { edit as hoursEdit } from '@/routes/store-settings/hours';
import { edit as printingEdit } from '@/routes/store-settings/printing';
import { edit as tablesEdit } from '@/routes/store-settings/tables';
import { index as teamIndex } from '@/routes/store-settings/team';
import { index as tablesIndex } from '@/routes/tables';
import { edit as messagesEdit } from '@/routes/store-settings/messages';
import { edit as onlinePaymentsEdit } from '@/routes/store-settings/online-payments';
import { edit as paymentsEdit } from '@/routes/store-settings/payments';
import type { NavItem } from '@/types';

const page = usePage();
const currentStore = computed(() => page.props.currentStore);

const mainNavItems = computed<NavItem[]>(() => {
    const items: NavItem[] = [
        {
            title: 'Início',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (currentStore.value?.canViewOrders) {
        items.push({
            title: 'Pedidos',
            href: ordersIndex(),
            icon: ReceiptText,
        });
    }

    if (currentStore.value?.hasDeliveries) {
        items.push({
            title: 'Minhas entregas',
            href: deliveriesIndex(),
            icon: Bike,
        });
    }

    if (currentStore.value?.canServeTables) {
        items.push({
            title: 'Mesas',
            href: tablesIndex(),
            icon: Armchair,
        });
    }

    if (currentStore.value?.canOperatePos) {
        items.push(
            {
                title: 'PDV',
                href: posShow(),
                icon: ShoppingCart,
            },
            {
                title: 'Caixa',
                href: cashIndex(),
                icon: Wallet,
            },
            {
                title: 'Entregadores',
                href: couriersMap(),
                icon: MapPinned,
            },
        );
    }

    if (currentStore.value?.canManageStore) {
        items.push({
            title: 'Relatórios',
            href: reportsIndex(),
            icon: ChartColumn,
        });
    }

    if (currentStore.value?.canManageFinance) {
        items.push(
            {
                title: 'Recebimentos',
                href: ledgerIndex(),
                icon: BookOpenCheck,
            },
            {
                title: 'Financeiro',
                href: financeOverview(),
                icon: Landmark,
            },
            {
                title: 'Contas a pagar',
                href: financePayables(),
                icon: FileText,
            },
            {
                title: 'Contas a receber',
                href: financeReceivables(),
                icon: HandCoins,
            },
            {
                title: 'Fornecedores',
                href: suppliersIndex(),
                icon: Factory,
            },
        );
    }

    if (currentStore.value?.canManageCatalog) {
        items.push(
            {
                title: 'Produtos',
                href: productsIndex(),
                icon: Package,
            },
            {
                title: 'Categorias',
                href: categoriesIndex(),
                icon: Tags,
            },
            {
                title: 'Grupos de opções',
                href: optionGroupsIndex(),
                icon: ListChecks,
            },
            {
                title: 'Pizzas',
                href: pizzasIndex(),
                icon: Pizza,
            },
        );
    }

    if (currentStore.value?.canManageStore) {
        items.push(
            {
                title: 'Dados e endereço',
                href: contactEdit(),
                icon: Store,
            },
            {
                title: 'Aparência',
                href: appearanceEdit(),
                icon: Palette,
            },
            {
                title: 'Entrega e funcionamento',
                href: deliveryEdit(),
                icon: Truck,
            },
            {
                title: 'Rede de entregadores',
                href: courierNetworkEdit(),
                icon: Navigation,
            },
            {
                title: 'Equipe',
                href: teamIndex(),
                icon: UsersRound,
            },
            {
                title: 'Mesas e taxa de serviço',
                href: tablesEdit(),
                icon: Armchair,
            },
            {
                title: 'Formas de pagamento',
                href: paymentsEdit(),
                icon: CreditCard,
            },
            {
                title: 'Pagamento online',
                href: onlinePaymentsEdit(),
                icon: QrCode,
            },
            {
                title: 'Horários',
                href: hoursEdit(),
                icon: Clock,
            },
            {
                title: 'Cupons',
                href: couponsIndex(),
                icon: TicketPercent,
            },
            {
                title: 'Banners',
                href: bannersIndex(),
                icon: GalleryHorizontal,
            },
            {
                title: 'Impressão',
                href: printingEdit(),
                icon: Printer,
            },
            {
                title: 'Mensagens do WhatsApp',
                href: messagesEdit(),
                icon: MessageCircle,
            },
        );
    }

    if (
        currentStore.value?.subscription.enabled &&
        currentStore.value.canManageStore
    ) {
        items.push({
            title: 'Assinatura',
            href: billingShow(),
            icon: BadgeCheck,
        });
    }

    if (page.props.auth.user?.is_super_admin) {
        items.push(
            {
                title: 'Administração',
                href: adminDashboard(),
                icon: ShieldCheck,
            },
            {
                title: 'Planos',
                href: adminPlans(),
                icon: Layers,
            },
            {
                title: 'Vouchers',
                href: adminVouchers(),
                icon: Ticket,
            },
            {
                title: 'Faturas',
                href: adminInvoices(),
                icon: FileSpreadsheet,
            },
            {
                title: 'Rede (admin)',
                href: adminNetwork(),
                icon: Route,
            },
        );
    }

    // Items of modules outside the plan stay visible, with a lock.
    const modules = currentStore.value?.subscription.modules ?? [];
    const moduleOf: Record<string, string> = {
        PDV: 'pos',
        Caixa: 'pos',
        Mesas: 'tables',
        'Mesas e taxa de serviço': 'tables',
        Entregadores: 'couriers',
        'Minhas entregas': 'couriers',
        Relatórios: 'reports',
        'Mensagens do WhatsApp': 'whatsapp',
        Cupons: 'marketing',
        Banners: 'marketing',
        'Pagamento online': 'online_payments',
        Financeiro: 'finance',
        'Contas a pagar': 'finance',
        'Contas a receber': 'finance',
        Fornecedores: 'finance',
    };

    return items.map((item) => ({
        ...item,
        locked:
            item.title in moduleOf && !modules.includes(moduleOf[item.title]),
    }));
});

function goToStore(id: string | number | null | undefined): void {
    if (id && Number(id) !== currentStore.value?.id) {
        router.post(switchStore.url(Number(id)));
    }
}

const footerNavItems = computed<NavItem[]>(() => [
    ...(currentStore.value
        ? [
              {
                  title: 'Ver meu cardápio',
                  href: menuShow.url(currentStore.value.slug),
                  icon: ExternalLink,
              },
          ]
        : []),
    // Offered in the browser only; inside the Windows app it is already installed.
    ...(page.props.desktopApp && !insideDesktopApp()
        ? [
              {
                  title: 'Baixar app para Windows',
                  href: page.props.desktopApp.url,
                  icon: MonitorDown,
              },
          ]
        : []),
    {
        title: 'Código no GitHub',
        href: 'https://github.com/fernando0319/OpenMenuExpress',
        icon: FolderGit2,
    },
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NativeSelect
                v-if="currentStore && currentStore.otherStores.length > 0"
                :model-value="currentStore.id"
                class="h-8 text-xs group-data-[collapsible=icon]:hidden"
                aria-label="Trocar de loja"
                @update:model-value="goToStore"
            >
                <option :value="currentStore.id">
                    {{ currentStore.name }}
                </option>
                <option
                    v-for="store in currentStore.otherStores"
                    :key="store.id"
                    :value="store.id"
                >
                    {{ store.name }}
                </option>
            </NativeSelect>
            <p
                v-else-if="currentStore"
                class="truncate px-2 text-xs text-muted-foreground group-data-[collapsible=icon]:hidden"
            >
                {{ currentStore.name }}
            </p>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
