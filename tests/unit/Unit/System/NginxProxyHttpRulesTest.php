<?php

namespace Tests\Unit\System;

use App\System;
use App\System\Filesystem;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\ProxyRulePorts;
use Tests\TestCase;

/**
 * An http proxy rule's vhost has to be something nginx loads, and its port,
 * when it is not 80 or 443, has to be open in the host firewall.
 */
class NginxProxyHttpRulesTest extends TestCase
{
    public function test_an_ipv6_listen_address_is_bracketed(): void
    {
        $conf = NginxProxy::renderHttpProxyRule($this->vars('2a01:4f8::1', 8081));

        $this->assertMatchesRegularExpression('/^\s*listen \[2a01:4f8::1\]:8081;$/m', $conf);
    }

    public function test_ipv4_and_wildcard_listens_are_as_before(): void
    {
        $this->assertMatchesRegularExpression('/^\s*listen 10\.0\.0\.5:8081;$/m', NginxProxy::renderHttpProxyRule($this->vars('10.0.0.5', 8081)));
        $this->assertMatchesRegularExpression('/^\s*listen 8081;$/m', NginxProxy::renderHttpProxyRule($this->vars('*', 8081)));
    }

    public function test_a_wildcard_listens_on_ipv6_too_when_the_host_has_it(): void
    {
        $conf = NginxProxy::renderHttpProxyRule($this->vars('*', 8081), true);
        $this->assertMatchesRegularExpression('/^\s*listen 8081;\n\s*listen \[::\]:8081;$/m', $conf);

        $vars = $this->vars('*', 8443);
        $vars['ssl_enabled'] = true;
        $this->assertMatchesRegularExpression('/^\s*listen \[::\]:8443 ssl;$/m', NginxProxy::renderHttpProxyRule($vars, true));

        // An address is still that address alone, and an IPv4-only host never gets [::].
        $this->assertStringNotContainsString('[::]', NginxProxy::renderHttpProxyRule($this->vars('10.0.0.5', 8081), true));
        $this->assertStringNotContainsString('[::]', NginxProxy::renderHttpProxyRule($this->vars('2a01:4f8::1', 8081), true));
        $this->assertStringNotContainsString('[::]', NginxProxy::renderHttpProxyRule($this->vars('*', 8081)));
    }

    public function test_ssl_follows_the_bracketed_address_and_log_names_keep_the_bare_one(): void
    {
        $vars = $this->vars('2a01:4f8::1', 8443);
        $vars['ssl_enabled'] = true;
        $conf = NginxProxy::renderHttpProxyRule($vars);

        $this->assertMatchesRegularExpression('/^\s*listen \[2a01:4f8::1\]:8443 ssl;$/m', $conf);
        $this->assertStringContainsString('access_log /var/log/nginx/proxy-rule-2a01:4f8::1:8443.access.log;', $conf);
    }

    public function test_a_host_that_never_had_a_rule_port_never_touches_the_firewall(): void
    {
        [$proxy, $ports, $files] = $this->proxy();

        $proxy->syncProxyRulePorts([$this->rule(1, 'http', 80), $this->rule(2, 'http', 443)]);
        $proxy->syncProxyRulePorts([]);

        $this->assertSame(0, $ports->syncs);
        $this->assertSame([], $files->getArrayCopy());
    }

    public function test_the_firewall_is_synced_until_the_last_rule_port_is_closed(): void
    {
        [$proxy, $ports, $files] = $this->proxy();
        $marker = '/opt/panelalpha/shared-hosting/webserver-config/nginx-proxy/proxy-rule-ports';
        $rules = [$this->rule(1, 'http', 8081), $this->rule(2, 'tcp', 25212)];

        $proxy->syncProxyRulePorts($rules);
        $this->assertSame(1, $ports->syncs);
        $this->assertSame(2, count(array_filter(explode("\n", $files[$marker]))));

        // The rules are gone: one more sync to take their allows back, then nothing.
        $proxy->syncProxyRulePorts([]);
        $proxy->syncProxyRulePorts([]);
        $this->assertSame(2, $ports->syncs);
        $this->assertSame("\n", $files[$marker]);
    }

    public function test_a_failed_sync_is_tried_again(): void
    {
        [$proxy, $ports, $files] = $this->proxy();
        $proxy->syncProxyRulePorts([$this->rule(1, 'http', 8081)]);

        $ports->works = false;
        $proxy->syncProxyRulePorts([]);
        $proxy->syncProxyRulePorts([]);

        $this->assertSame(3, $ports->syncs);
    }

    /**
     * @return array{object, object, \ArrayObject<string, string>}
     */
    private function proxy(): array
    {
        $files = new \ArrayObject();
        $system = new class ($files) extends System {
            /** @param \ArrayObject<string, string> $files */
            public function __construct(private \ArrayObject $files)
            {
            }

            public function engineDirPath(): string
            {
                return '/opt/panelalpha/shared-hosting';
            }

            public function filesystem(): Filesystem
            {
                return new class ($this, $this->files) extends Filesystem {
                    /** @param \ArrayObject<string, string> $files */
                    public function __construct(System $system, private \ArrayObject $files)
                    {
                        parent::__construct($system);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->files[$path] ?? throw new \RuntimeException("cp: cannot stat '{$path}'");
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->files[$path] = $contents;
                    }
                };
            }
        };

        $ports = new class extends ProxyRulePorts {
            public int $syncs = 0;
            public bool $works = true;

            public function sync(array $rules): bool
            {
                $this->syncs++;

                return $this->works;
            }
        };

        $proxy = new class ($system, $ports) extends NginxProxy {
            public function __construct(System $system, private ProxyRulePorts $ports)
            {
                parent::__construct($system);
            }

            protected function proxyRulePorts(): ProxyRulePorts
            {
                return $this->ports;
            }
        };

        return [$proxy, $ports, $files];
    }

    /** @return array{id: int, transport: string, listen_ip: string, listen_port: int, is_generated: bool} */
    private function rule(int $id, string $transport, int $port): array
    {
        return ['id' => $id, 'transport' => $transport, 'listen_ip' => '*', 'listen_port' => $port, 'is_generated' => false];
    }

    /**
     * @return array{id: int, listen_ip: string, listen_port: int, server_names: array<string>, upstream_protocol: string,
     *   upstream_host: string, upstream_port: int, ssl_enabled: bool}
     */
    private function vars(string $ip, int $port): array
    {
        return [
            'id' => 3,
            'listen_ip' => $ip,
            'listen_port' => $port,
            'server_names' => ['_'],
            'upstream_protocol' => 'http',
            'upstream_host' => 'tr12i',
            'upstream_port' => 3000,
            'ssl_enabled' => false,
        ];
    }
}
