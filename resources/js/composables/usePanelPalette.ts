import { usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, watchEffect } from 'vue';

const STYLE_ID = 'store-panel-palette';

/**
 * Paints the merchant panel with the store's color, in light and dark mode.
 * The colors were already adapted on the server so they read well in each mode;
 * the customer menu (html.menu-theme) keeps its own rules.
 */
export function usePanelPalette(): void {
    const page = usePage();

    watchEffect(() => {
        if (typeof document === 'undefined') {
            return;
        }

        const palette = page.props.currentStore?.palette;
        let style = document.getElementById(STYLE_ID);

        if (!palette) {
            style?.remove();

            return;
        }

        if (!style) {
            style = document.createElement('style');
            style.id = STYLE_ID;
            document.head.appendChild(style);
        }

        const block = (primary: string, foreground: string): string =>
            [
                `--primary: ${primary};`,
                `--primary-foreground: ${foreground};`,
                `--ring: ${primary};`,
                `--chart-1: ${primary};`,
                `--sidebar-primary: ${primary};`,
                `--sidebar-primary-foreground: ${foreground};`,
                `--sidebar-ring: ${primary};`,
            ].join(' ');

        style.textContent = [
            `:root:not(.menu-theme) { ${block(palette.light.primary, palette.light.foreground)} }`,
            `:root.dark:not(.menu-theme) { ${block(palette.dark.primary, palette.dark.foreground)} }`,
        ].join('\n');
    });

    onBeforeUnmount(() => document.getElementById(STYLE_ID)?.remove());
}
