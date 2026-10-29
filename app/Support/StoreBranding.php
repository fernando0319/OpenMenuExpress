<?php

namespace App\Support;

use App\Models\Store;

/**
 * How a store looks on the customer menu: logo, cover, colors and theme.
 * Any theme receives the same branding, so a store keeps its identity when switching themes.
 *
 * @phpstan-type Branding array{logoUrl: string|null, coverUrl: string|null, primaryColor: string, accentColor: string, theme: string}
 */
final class StoreBranding
{
    public const DEFAULT_PRIMARY = '#5b21b6';

    public const DEFAULT_ACCENT = '#2dd4bf';

    /** Themes available today. More (official or from developers) will be registered here. */
    public const THEMES = ['default'];

    /**
     * Ready-made color pairs shown in the settings screen.
     *
     * @var list<array{name: string, primary: string, accent: string}>
     */
    public const PRESETS = [
        ['name' => 'Roxo e verde-água', 'primary' => '#5b21b6', 'accent' => '#2dd4bf'],
        ['name' => 'Vermelho e laranja', 'primary' => '#b91c1c', 'accent' => '#f97316'],
        ['name' => 'Verde e lima', 'primary' => '#15803d', 'accent' => '#a3e635'],
        ['name' => 'Azul e ciano', 'primary' => '#1d4ed8', 'accent' => '#22d3ee'],
        ['name' => 'Grafite e dourado', 'primary' => '#1f2937', 'accent' => '#f59e0b'],
        ['name' => 'Rosa e pêssego', 'primary' => '#be185d', 'accent' => '#fb923c'],
    ];

    /**
     * @return Branding
     */
    public static function for(Store $store): array
    {
        return [
            'logoUrl' => ImageStorage::url($store->getAttribute('logo_path')),
            'coverUrl' => ImageStorage::url($store->getAttribute('cover_path')),
            'primaryColor' => self::color($store->getAttribute('primary_color'), self::DEFAULT_PRIMARY),
            'accentColor' => self::color($store->getAttribute('accent_color'), self::DEFAULT_ACCENT),
            'theme' => in_array($store->getAttribute('theme'), self::THEMES, true) ? (string) $store->getAttribute('theme') : 'default',
        ];
    }

    /** Panel surfaces the store color must stand out against (see app.css). */
    private const PANEL_LIGHT_TEXT = '#ffffff';

    private const PANEL_DARK_SURFACE = '#111115';

    /** The default purple, lightened for the dark panel (matches app.css). */
    private const DEFAULT_PANEL_DARK = '#b9a2f5';

    /** Text contrast the panel aims for (WCAG AA for normal text). */
    private const PANEL_CONTRAST = 4.5;

    /** Beyond this much mixing the color stops looking like the store's: use the default. */
    private const MAX_MIX = 0.45;

    /**
     * The store color adapted to the merchant panel in each mode.
     * Light mode: darkened only if white text on it would be hard to read.
     * Dark mode: lightened until it reads on the dark surface, with dark text on top.
     *
     * @return array{light: array{primary: string, foreground: string}, dark: array{primary: string, foreground: string}, custom: bool}
     */
    public static function panelPalette(Store $store): array
    {
        $base = self::color($store->getAttribute('primary_color'), self::DEFAULT_PRIMARY);

        $light = self::adjust($base, '#000000', fn (string $hex) => self::contrast($hex, self::PANEL_LIGHT_TEXT));
        $dark = self::adjust($base, '#ffffff', fn (string $hex) => self::contrast($hex, self::PANEL_DARK_SURFACE));

        // Same identity in both modes: if the color fails in either, both use the defaults.
        $custom = $light !== null && $dark !== null && $base !== self::DEFAULT_PRIMARY;

        return [
            'light' => ['primary' => $custom ? (string) $light : self::DEFAULT_PRIMARY, 'foreground' => '#ffffff'],
            'dark' => ['primary' => $custom ? (string) $dark : self::DEFAULT_PANEL_DARK, 'foreground' => self::PANEL_DARK_SURFACE],
            'custom' => $custom,
        ];
    }

    /**
     * Too light for white text on top (buttons, the cart button)? Uses WCAG relative luminance:
     * white text needs about 3:1 contrast for large, bold text.
     */
    public static function isTooLight(string $hex): bool
    {
        return self::contrastWithWhite($hex) < 3.0;
    }

    public static function contrastWithWhite(string $hex): float
    {
        return self::contrast($hex, '#ffffff');
    }

    /**
     * WCAG contrast ratio between two colors (1 to 21).
     */
    public static function contrast(string $first, string $second): float
    {
        $a = self::luminance($first);
        $b = self::luminance($second);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * Mixes the color toward black or white in small steps until it passes, or null if it would
     * have to change too much.
     *
     * @param  callable(string): float  $contrastOf
     */
    private static function adjust(string $hex, string $toward, callable $contrastOf): ?string
    {
        for ($mix = 0.0; $mix <= self::MAX_MIX + 1e-9; $mix += 0.05) {
            $candidate = self::mix($hex, $toward, $mix);

            if ($contrastOf($candidate) >= self::PANEL_CONTRAST) {
                return $candidate;
            }
        }

        return null;
    }

    private static function mix(string $hex, string $toward, float $amount): string
    {
        $from = self::channels($hex);
        $to = self::channels($toward);
        $mixed = array_map(fn (int $a, int $b) => (int) round($a + ($b - $a) * $amount), $from, $to);

        return sprintf('#%02x%02x%02x', ...$mixed);
    }

    /**
     * @return list<int>
     */
    private static function channels(string $hex): array
    {
        return array_map(fn (string $pair) => (int) hexdec($pair), str_split(ltrim($hex, '#'), 2));
    }

    private static function luminance(string $hex): float
    {
        $linear = array_map(function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    private static function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : $default;
    }
}
