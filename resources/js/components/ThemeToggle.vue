<script setup lang="ts">
import { Moon, Sun } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/composables/useAppearance';

/**
 * One click between light and dark. "System" stays available in Settings › Appearance.
 */
const { resolvedAppearance, updateAppearance } = useAppearance();

function toggle(): void {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
}
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        :aria-label="
            resolvedAppearance === 'dark'
                ? 'Usar modo claro'
                : 'Usar modo escuro'
        "
        :title="resolvedAppearance === 'dark' ? 'Modo claro' : 'Modo escuro'"
        @click="toggle"
    >
        <Sun v-if="resolvedAppearance === 'dark'" />
        <Moon v-else />
    </Button>
</template>
