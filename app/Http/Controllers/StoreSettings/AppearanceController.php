<?php

namespace App\Http\Controllers\StoreSettings;

use App\Http\Controllers\Concerns\InteractsWithCurrentStore;
use App\Http\Controllers\Controller;
use App\Support\ImageStorage;
use App\Support\StoreBranding;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the store presents itself on the menu: name, description, logo, cover and colors.
 */
class AppearanceController extends Controller
{
    use InteractsWithCurrentStore;

    public function __construct(private readonly ImageStorage $images) {}

    public function edit(): Response
    {
        $store = $this->currentStore();

        return Inertia::render('store-settings/Appearance', [
            'store' => [
                'name' => $store->name,
                'slug' => $store->slug,
                'description' => $store->getAttribute('description'),
            ],
            'branding' => StoreBranding::for($store),
            'presets' => StoreBranding::PRESETS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $store = $this->currentStore();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme' => ['nullable', Rule::in(StoreBranding::THEMES)],
            'logo' => ['nullable', ...ImageStorage::rules()],
            'cover' => ['nullable', ...ImageStorage::rules()],
            'remove_logo' => ['boolean'],
            'remove_cover' => ['boolean'],
        ], [
            'primary_color.regex' => 'Escolha uma cor válida.',
            'accent_color.regex' => 'Escolha uma cor válida.',
        ]);

        $paths = [];

        foreach (['logo' => 'logo_path', 'cover' => 'cover_path'] as $field => $column) {
            $current = $store->getAttribute($column);

            if ($request->hasFile($field)) {
                $file = $request->file($field);

                if ($file instanceof UploadedFile) {
                    $this->images->delete(is_string($current) ? $current : null);
                    $paths[$column] = $this->images->store($file, $store, 'branding');
                }
            } elseif ($request->boolean("remove_{$field}")) {
                $this->images->delete(is_string($current) ? $current : null);
                $paths[$column] = null;
            }
        }

        $store->update([
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'primary_color' => strtolower($data['primary_color']),
            'accent_color' => strtolower($data['accent_color']),
            'theme' => $data['theme'] ?? 'default',
            ...$paths,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Aparência salva. Veja como ficou no seu cardápio.']);

        return back();
    }
}
