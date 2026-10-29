<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import PendingOrdersAlert from '@/components/PendingOrdersAlert.vue';
import SubscriptionBanner from '@/components/SubscriptionBanner.vue';
import SupportBanner from '@/components/SupportBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import { usePanelPalette } from '@/composables/usePanelPalette';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// The store's color on buttons, links and charts, adapted to light and dark mode.
usePanelPalette();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <SupportBanner />
            <SubscriptionBanner />
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <PendingOrdersAlert />
        <Toaster />
    </AppShell>
</template>
