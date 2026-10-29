<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { Button } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { show as menuShow } from '@/routes/menu';
import type { BreadcrumbItem } from '@/types';

const page = usePage();

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>
        <div class="ml-auto flex items-center gap-1">
            <Button
                v-if="page.props.currentStore"
                as-child
                variant="ghost"
                size="sm"
                class="hidden sm:inline-flex"
            >
                <a
                    :href="menuShow.url(page.props.currentStore.slug)"
                    target="_blank"
                    rel="noopener"
                >
                    <ExternalLink />
                    Ver cardápio
                </a>
            </Button>
            <ThemeToggle />
        </div>
    </header>
</template>
