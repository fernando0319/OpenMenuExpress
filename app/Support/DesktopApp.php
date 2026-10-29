<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

/**
 * The Windows panel app the "desktop" workflow publishes to storage/app/public/desktop:
 * the installer plus latest.json, which the installed apps also read to update themselves.
 */
final class DesktopApp
{
    public const INSTALLER = 'desktop/Menu-Express-Setup.exe';

    public const MANIFEST = 'desktop/latest.json';

    /**
     * @return array{url: string, version: string}|null
     */
    public static function latest(): ?array
    {
        $disk = Storage::disk('public');

        if (! $disk->exists(self::INSTALLER) || ! $disk->exists(self::MANIFEST)) {
            return null;
        }

        $manifest = json_decode((string) $disk->get(self::MANIFEST), true);
        $version = is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : '';

        return ['url' => $disk->url(self::INSTALLER), 'version' => $version];
    }
}
