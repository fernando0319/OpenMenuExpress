<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores pictures uploaded by merchants on the public disk, one folder per store.
 * Served at /storage (run `php artisan storage:link` once per installation).
 */
final class ImageStorage
{
    /**
     * Validation rules shared by every image upload.
     *
     * @return list<string>
     */
    public static function rules(): array
    {
        return ['image', 'mimes:jpg,jpeg,png,webp', 'max:4096'];
    }

    public function store(UploadedFile $file, Store $store, string $folder): string
    {
        $name = Str::random(32).'.'.($file->guessExtension() ?? 'jpg');

        return $file->storeAs("stores/{$store->id}/{$folder}", $name, 'public') ?: '';
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk('public')->delete($path);
        }
    }

    public static function url(?string $path): ?string
    {
        return $path === null || $path === '' ? null : Storage::disk('public')->url($path);
    }
}
