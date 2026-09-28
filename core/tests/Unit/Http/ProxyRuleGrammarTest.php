<?php

namespace Tests\Unit\Http;

use App\Rules\ListenIp;
use App\Rules\ProxyServerName;
use App\Rules\SshPublicKey;
use App\Rules\UpstreamHost;
use PHPUnit\Framework\TestCase;

/**
 * Values the proxy-rule and SFTP endpoints write verbatim into nginx or sshd
 * config. Each rule is the whole defence, so both directions are pinned.
 */
class ProxyRuleGrammarTest extends TestCase
{
    public function test_upstream_host(): void
    {
        foreach (['127.0.0.1', '::1', 'app', 'my_app', 'db.internal', 'acme-web-1'] as $ok) {
            $this->assertTrue(UpstreamHost::isValid($ok), $ok);
        }
        foreach (["app;\n}", 'app evil', 'app}', 'a$b', '', "app\nserver x;"] as $bad) {
            $this->assertFalse(UpstreamHost::isValid($bad), json_encode($bad));
        }
    }

    public function test_listen_ip(): void
    {
        foreach (['*', '10.0.0.5', '2001:db8::1'] as $ok) {
            $this->assertTrue(ListenIp::isValid($ok), $ok);
        }
        foreach (['0.0.0.0:80; server {', 'localhost', "1.2.3.4\n"] as $bad) {
            $this->assertFalse(ListenIp::isValid($bad), json_encode($bad));
        }
    }

    public function test_server_name(): void
    {
        foreach (['_', 'example.com', '*.example.com', '.example.com', 'localhost'] as $ok) {
            $this->assertTrue(ProxyServerName::isValid($ok), $ok);
        }
        foreach (['a.com; location / { root /; }', 'a.com b.com', "a.com\n", '*', ''] as $bad) {
            $this->assertFalse(ProxyServerName::isValid($bad), json_encode($bad));
        }
    }

    public function test_ssh_public_key(): void
    {
        $this->assertTrue(SshPublicKey::isValid('ssh-rsa AAAAB3NzaC1yc2E= me@host'));
        $this->assertTrue(SshPublicKey::isValid('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGxzZWNyZXQ'));
        $this->assertFalse(SshPublicKey::isValid("ssh-ed25519 AAAA\nrogue::0:0:/home/victim:"));
        $this->assertFalse(SshPublicKey::isValid('ssh-ed25519 AAAA:0:0'));
        $this->assertFalse(SshPublicKey::isValid('ssh-ed25519 AAAA "$(id)"'));
        $this->assertFalse(SshPublicKey::isValid('not-a-key AAAA'));
    }
}
