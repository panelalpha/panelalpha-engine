<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\ViteOwnUrlEnv;
use PHPUnit\Framework\TestCase;

class ViteOwnUrlEnvTest extends TestCase
{
    private const OWN = 'https://app.example.com';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-vite-own-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/src/lib', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    private function source(string $name, string $contents): void
    {
        file_put_contents($this->dir.'/src/'.$name, $contents);
    }

    public function test_a_localhost_fallback_points_at_the_apps_own_address_with_its_path(): void
    {
        $this->source('lib/api.ts', 'const API_BASE = import.meta.env.VITE_API_BASE || "http://localhost:8787";');
        $this->source('lib/admin.ts', <<<'TS'
            const ADMIN =
              (import.meta as any).env?.VITE_ADMIN_API ||
              ((import.meta as any).env?.PROD
                ? "https://admin.onrender.com/api"
                : "http://localhost:4000/api");
            TS);
        $this->source('Form.tsx', "const url = import.meta.env.VITE_API_URL || (import.meta.env.DEV ? '' : 'http://127.0.0.1:3001');");

        $this->assertSame([
            'VITE_ADMIN_API' => self::OWN.'/api',
            'VITE_API_BASE' => self::OWN,
            'VITE_API_URL' => self::OWN,
        ], ViteOwnUrlEnv::values($this->dir, self::OWN, []));
    }

    public function test_a_websocket_fallback_keeps_a_websocket_scheme(): void
    {
        $this->source('ws.ts', "const url = import.meta.env.VITE_WS_URL || 'ws://localhost:3001/socket';");

        $this->assertSame(['VITE_WS_URL' => 'wss://app.example.com/socket'], ViteOwnUrlEnv::values($this->dir, self::OWN, []));
        $this->assertSame(['VITE_WS_URL' => 'ws://app.test/socket'], ViteOwnUrlEnv::values($this->dir, 'http://app.test/', []));
    }

    public function test_a_real_address_the_project_gives_is_kept(): void
    {
        $this->source('api.ts', 'const a = import.meta.env.VITE_API_URL || "http://localhost:3001"; const b = import.meta.env.VITE_WS || "ws://localhost:3001";');

        $this->assertSame(
            ['VITE_WS' => 'wss://app.example.com'],
            ViteOwnUrlEnv::values($this->dir, self::OWN, ['VITE_API_URL' => 'https://api.example.org', 'VITE_WS' => 'ws://localhost:9'])
        );
    }

    public function test_names_without_a_localhost_fallback_are_left_alone(): void
    {
        $this->source('keys.ts', "const key = import.meta.env.VITE_GEMINI_API_KEY || ''; const site = import.meta.env.VITE_SITE_URL || 'https://example.org';");

        $this->assertSame([], ViteOwnUrlEnv::values($this->dir, self::OWN, []));
    }

    public function test_without_an_address_of_its_own_nothing_is_set(): void
    {
        $this->source('api.ts', 'const a = import.meta.env.VITE_API_URL || "http://localhost:3001";');

        $this->assertSame([], ViteOwnUrlEnv::values($this->dir, null, []));
        $this->assertSame([], ViteOwnUrlEnv::values($this->dir.'/missing', self::OWN, []));
    }
}
