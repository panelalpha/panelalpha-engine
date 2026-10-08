<?php

namespace Tests\Unit\Apis;

use App\Lib\Apis\PanelAlpha;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelalphaTest extends TestCase
{
    public function test_url_joins_base_and_path(): void
    {
        $this->assertSame(
            'https://connect.example.test/api/without-dns/sites',
            PanelAlpha::url('https://connect.example.test', 'api/without-dns/sites')
        );
    }

    public function test_url_strips_trailing_and_leading_slashes(): void
    {
        $this->assertSame(
            'https://connect.example.test/api/v1/events',
            PanelAlpha::url('https://connect.example.test/', '/api/v1/events')
        );
    }

    public function test_empty_base_yields_empty_string_not_a_relative_url(): void
    {
        $this->assertSame('', PanelAlpha::url('', '/api/v1/events'));
        $this->assertSame('', PanelAlpha::url('   ', 'anything'));
    }

    public function test_identity_headers_carry_app_uid(): void
    {
        config(['app.uid' => '7f3c2a1e-0b4d-4c6e-9a8f-1d2e3f4a5b6c']);

        $this->assertSame(
            ['X-Engine-App-UID' => '7f3c2a1e-0b4d-4c6e-9a8f-1d2e3f4a5b6c'],
            PanelAlpha::identityHeaders()
        );
    }

    public function test_identity_headers_are_empty_without_a_usable_uid(): void
    {
        foreach ([null, '', '   ', "abc\r\nX-Injected: 1", str_repeat('a', 129)] as $uid) {
            config(['app.uid' => $uid]);
            $this->assertSame([], PanelAlpha::identityHeaders(), var_export($uid, true));
        }
    }

    public function test_connect_client_sends_app_uid(): void
    {
        config(['app.uid' => 'install-42']);
        Setting::setRuntimeSettings(['license_key' => '']);
        Http::fake(['*' => Http::response([], 200)]);

        PanelAlpha::http()->post('https://connect.example.test/api/without-dns/sites', []);

        Http::assertSent(fn ($request): bool => $request->header('X-Engine-App-UID') === ['install-42']);
        Setting::clearRuntimeSettings();
    }
}
