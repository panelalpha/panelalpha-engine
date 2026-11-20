<?php

namespace Tests\Integration\System\Project;

use App\Models\Tunnel;
use App\Models\User;
use App\System;
use App\System\Project\SitePasswordProtection;
use App\System\Services\Webserver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** Setting and removing a site password, with every broker request answered by a recording client. */
class SitePasswordProtectionSetTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    private string $requests;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();

        $this->tmpRoot = sys_get_temp_dir() . '/site-password-' . bin2hex(random_bytes(4));
        mkdir($this->tmpRoot, 0755, true);
        $this->requests = $this->tmpRoot . '/requests.log';
        $this->answerBrokerWith('nginx-proxy');
    }

    protected function tearDown(): void
    {
        LocalRoot::answerByBroker();
        $this->rememberWebserver(null);
        File::deleteDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function test_setting_a_password_stores_a_hash_and_queues_a_proxy_reload(): void
    {
        $user = $this->makeUser('shop');

        SitePasswordProtection::set($user, "s3cret pass\n");

        $user->refresh();
        $this->assertTrue(SitePasswordProtection::isEnabled($user));
        $this->assertSame(1, SitePasswordProtection::version($user));
        $this->assertTrue(SitePasswordProtection::verifyPassword($user, 's3cret pass'));
        $this->assertFalse(SitePasswordProtection::verifyPassword($user, "s3cret pass\n"));
        $this->assertContains('webserver.queueReload', $this->askedActions());
    }

    public function test_replacing_a_password_raises_the_version_so_old_cookies_stop_working(): void
    {
        $user = $this->makeUser('shop');
        SitePasswordProtection::set($user, 'first');
        $cookie = SitePasswordProtection::cookieValue($user);

        SitePasswordProtection::set($user, 'second');

        $user->refresh();
        $this->assertSame(2, SitePasswordProtection::version($user));
        $this->assertFalse(SitePasswordProtection::cookieIsValid($user, $cookie));
        $this->assertTrue(SitePasswordProtection::verifyPassword($user, 'second'));
    }

    public function test_an_empty_password_is_refused_and_nothing_is_stored(): void
    {
        $user = $this->makeUser('shop');

        try {
            SitePasswordProtection::set($user, "\r\n");
            $this->fail('An empty password was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Password must not be empty.', $e->getMessage());
        }

        $this->assertFalse(SitePasswordProtection::isEnabled($user->refresh()));
        $this->assertSame([], $this->askedActions());
    }

    public function test_unsetting_forgets_every_password_detail_and_keeps_the_others(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind']);
        SitePasswordProtection::set($user, 'pw');

        SitePasswordProtection::unset($user);

        $details = $user->refresh()->getDetails();
        $this->assertArrayNotHasKey(SitePasswordProtection::DETAIL_ENABLED, $details);
        $this->assertArrayNotHasKey(SitePasswordProtection::DETAIL_HASH, $details);
        $this->assertArrayNotHasKey(SitePasswordProtection::DETAIL_VERSION, $details);
        $this->assertSame('dind', $details['template']);
        $this->assertSame(['site_password_enabled' => false], SitePasswordProtection::nginxTemplateVars($user));
    }

    public function test_unsetting_an_unprotected_project_asks_for_nothing(): void
    {
        $user = $this->makeUser('shop');

        SitePasswordProtection::unset($user);

        $this->assertSame([], $this->askedActions());
    }

    public function test_a_webserver_other_than_nginx_proxy_is_warned_about(): void
    {
        Log::spy();
        $this->rememberWebserver('apache');
        $user = $this->makeUser('shop');

        SitePasswordProtection::set($user, 'pw');

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => str_contains($message, "project 'shop'")
                && str_contains($message, "webserver is 'apache'")
        )->once();
    }

    public function test_a_dind_project_with_a_cloudflare_tunnel_syncs_its_origins(): void
    {
        Log::spy();
        $user = $this->makeUser('shop', ['template' => 'dind']);
        $other = $this->makeUser('other');
        $domain = $this->makeMainDomain($other, 'other.example.test');
        Tunnel::query()->create([
            'domain_id' => $domain->id,
            'user_id' => $user->id,
            'provider' => Tunnel::PROVIDER_CLOUDFLARE,
            'hostname' => 'shop.tunnel.example.test',
        ]);

        SitePasswordProtection::set($user, 'pw');

        $this->assertTrue(SitePasswordProtection::isEnabled($user->refresh()));
        $this->assertTrue($user->relationLoaded('domains'));
        Log::shouldNotHaveReceived('warning');
    }

    public function test_a_failed_cache_ownership_reclaim_is_only_logged(): void
    {
        Log::spy();
        $cache = storage_path('framework/cache');
        if (!is_dir($cache)) {
            mkdir($cache, 0755, true);
        }
        $runner = new class () extends System {
            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                throw new \RuntimeException('broker is down');
            }
        };

        SitePasswordProtection::reclaimFrameworkCacheOwnership(0, $runner);

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message): bool => $message === 'Could not reclaim framework cache ownership for site-password gate: broker is down'
        )->once();
    }

    public function test_malformed_basic_headers_carry_no_password(): void
    {
        $this->assertNull(SitePasswordProtection::passwordFromBasicHeader(''));
        $this->assertNull(SitePasswordProtection::passwordFromBasicHeader('Basic !!!not-base64!!!'));
        $this->assertNull(SitePasswordProtection::passwordFromBasicHeader('Basic ' . base64_encode('no-colon')));
    }

    public function test_cookies_that_cannot_be_valid_are_refused(): void
    {
        $user = new User();
        $user->username = 'shop';
        $user->details = [
            'site_password_enabled' => true,
            'site_password_hash' => password_hash('pw', PASSWORD_BCRYPT),
        ];

        $this->assertFalse(SitePasswordProtection::cookieIsValid($user, null));
        $this->assertFalse(SitePasswordProtection::cookieIsValid($user, ''));
        $this->assertFalse(SitePasswordProtection::cookieIsValid($user, 'no-dot'));
        $this->assertFalse(SitePasswordProtection::cookieIsValid($user, 'x.signature'));
        $this->assertTrue(SitePasswordProtection::cookieIsValid($user, SitePasswordProtection::cookieValue($user)));
    }

    public function test_a_project_without_a_hash_verifies_no_password(): void
    {
        $user = new User();
        $user->details = ['site_password_enabled' => true];

        $this->assertFalse(SitePasswordProtection::isEnabled($user));
        $this->assertFalse(SitePasswordProtection::verifyPassword($user, ''));
    }

    public function test_an_empty_mode_setting_falls_back_to_the_custom_form(): void
    {
        config(['env.SITE_PASSWORD_AUTH_MODE' => '']);

        $this->assertSame(SitePasswordProtection::MODE_CUSTOM, SitePasswordProtection::authMode());
    }

    public function test_the_cookie_is_signed_with_a_plain_key_and_without_one(): void
    {
        $user = new User();
        $user->username = 'shop';
        $user->details = ['site_password_version' => 4];

        config(['app.key' => 'plain-key']);
        $this->assertSame('4.' . hash_hmac('sha256', 'shop|4', 'plain-key'), SitePasswordProtection::cookieValue($user));

        config(['app.key' => '']);
        $this->assertSame('4.' . hash_hmac('sha256', 'shop|4', 'pa-site-password'), SitePasswordProtection::cookieValue($user));
    }

    /** @return list<string> */
    private function askedActions(): array
    {
        if (!is_file($this->requests)) {
            return [];
        }
        $actions = [];
        foreach (file($this->requests, FILE_IGNORE_NEW_LINES) as $line) {
            $action = LocalRoot::action(explode(' ', $line));
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    private function answerBrokerWith(string $webserver): void
    {
        $client = $this->tmpRoot . '/pa-root';
        $labels = json_encode(['Labels' => "com.panelalpha.webserver={$webserver}"]);
        file_put_contents($client, implode("\n", [
            '#!/bin/sh',
            'echo "$0 $*" >> ' . escapeshellarg($this->requests),
            'case "$*" in *compose.serviceContainers*) echo ' . escapeshellarg((string) $labels) . ' ;; esac',
            'exit 0',
            '',
        ]));
        chmod($client, 0755);
        LocalRoot::answerWith($client);
    }

    private function rememberWebserver(?string $webserver): void
    {
        (new \ReflectionProperty(Webserver::class, 'currentWebserver'))->setValue(null, $webserver);
    }
}
