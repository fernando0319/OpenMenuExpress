<?php

namespace Tests\Feature\Panel;

use App\Support\DesktopApp;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DesktopAppTest extends TestCase
{
    public function test_the_download_shows_up_once_the_installer_and_manifest_are_published(): void
    {
        Storage::fake('public');

        $this->assertNull(DesktopApp::latest());

        Storage::disk('public')->put(DesktopApp::INSTALLER, 'exe');
        $this->assertNull(DesktopApp::latest(), 'Without latest.json the version is unknown.');

        Storage::disk('public')->put(DesktopApp::MANIFEST, json_encode(['version' => '1.0.7']) ?: '');

        $latest = DesktopApp::latest();
        $this->assertSame('1.0.7', $latest['version'] ?? null);
        $this->assertStringEndsWith('/desktop/Menu-Express-Setup.exe', $latest['url'] ?? '');
    }
}
