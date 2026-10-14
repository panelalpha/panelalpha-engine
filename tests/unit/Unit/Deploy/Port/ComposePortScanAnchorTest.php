<?php

namespace Tests\Unit\Deploy\Port;

use App\Lib\Deploy\Port\ComposePortScan;
use PHPUnit\Framework\TestCase;

/**
 * The port scan parsed compose files with Symfony directly while every other
 * reader went through ComposeYaml, which carries the anchor rescue. On
 * Baserow that gave one `source_inspect` response nine services and no ports
 * at all -- and the parse failure was swallowed by a bare catch, so nothing
 * said why. 80 and 443 are exactly the ports the engine can proxy.
 */
class ComposePortScanAnchorTest extends TestCase
{
    private string $file = '';

    protected function tearDown(): void
    {
        if ($this->file !== '' && is_file($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    private function write(string $yaml): string
    {
        $this->file = sys_get_temp_dir() . '/pa-portscan-' . bin2hex(random_bytes(6)) . '.yml';
        file_put_contents($this->file, $yaml);

        return $this->file;
    }

    public function test_an_anchor_with_a_comment_does_not_hide_the_ports(): void
    {
        $path = $this->write(
            "x-vars:\n"
            . "  &vars # Most users should only need these.\n"
            . "  SECRET_KEY: secret\n"
            . "services:\n"
            . "  caddy:\n"
            . "    image: caddy:2.11.4\n"
            . "    ports:\n"
            . "      - \"\${HOST_PUBLISH_IP:-0.0.0.0}:\${WEB_FRONTEND_PORT:-80}:80\"\n"
            . "      - \"\${HOST_PUBLISH_IP:-0.0.0.0}:\${WEB_FRONTEND_SSL_PORT:-443}:443\"\n"
        );

        $this->assertSame([80, 443], ComposePortScan::of($path)['all']);
        $this->assertSame(80, ComposePortScan::of($path)['primary']);
    }

    /** An ordinary file is unaffected. */
    public function test_a_plain_compose_still_scans(): void
    {
        $path = $this->write("services:\n  app:\n    image: nginx\n    ports:\n      - '8080:80'\n");

        $this->assertSame([8080], ComposePortScan::of($path)['all']);
    }

    /** Genuinely unreadable YAML still answers empty rather than throwing. */
    public function test_unreadable_yaml_answers_empty(): void
    {
        $path = $this->write("services:\n  app:\n   image: [unclosed\n");

        $this->assertSame([], ComposePortScan::of($path)['all']);
    }
}
