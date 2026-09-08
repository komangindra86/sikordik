<?php

namespace Tests\Feature;

use Tests\TestCase;

class PwaTest extends TestCase
{
    public function test_pwa_assets_exist_and_navigation_is_not_cached(): void
    {
        $this->assertFileExists(public_path('manifest.webmanifest'));
        $this->assertFileExists(public_path('service-worker.js'));
        $this->assertFileExists(public_path('offline.html'));
        $this->assertFileExists(public_path('icons/sikordik-192.png'));
        $this->assertFileExists(public_path('icons/sikordik-512.png'));
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertStringContainsString("request.mode === 'navigate'", file_get_contents(public_path('service-worker.js')));
        $this->assertStringNotContainsString('cache.put(event.request', strstr(file_get_contents(public_path('service-worker.js')), "if (event.request.mode === 'navigate')", true) ?: '');
    }
}
