<?php

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;

/**
 * :2011 proxies to the :80 server, which hides X-Accel-Buffering unless told
 * otherwise, and :2011 would then hold the NDJSON streams until they end.
 */
class CoreNginxStreamingTest extends TestCase
{
    private function config(): string
    {
        $path = __DIR__ . '/../../../../config/core/nginx.conf';
        if (!is_file($path)) {
            $this->markTestSkipped('config/core/nginx.conf is not beside core/ here');
        }

        return (string) file_get_contents($path);
    }

    public function test_the_php_location_hands_the_buffering_choice_on_to_the_api_port(): void
    {
        $conf = $this->config();
        $php = substr($conf, (int) strpos($conf, 'location ~ \.php$ {'));
        $php = substr($php, 0, (int) strpos($php, '}'));

        $this->assertStringContainsString('fastcgi_pass_header X-Accel-Buffering;', $php);
    }

    public function test_the_api_port_does_not_pass_the_header_on_to_its_client(): void
    {
        $conf = $this->config();
        $api = substr($conf, (int) strpos($conf, 'listen 2011 ssl;'));

        $this->assertStringNotContainsString('proxy_pass_header X-Accel-Buffering;', $api);
    }
}
