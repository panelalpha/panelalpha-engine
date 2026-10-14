<?php

namespace Tests\Unit;

use App\System;
use App\System\Services\Webserver\NginxProxy;
use PHPUnit\Framework\TestCase;

/**
 * A vhost whose domain no longer exists must not be left on disk.
 *
 * `rebuildDomainConfigs()` only ever wrote the vhosts of the domains still in
 * the database, so a conf belonging to a deleted account was neither rewritten
 * nor removed -- the loop did not know it existed. The leftover names a
 * certificate that went with the account, one vhost naming a missing file makes
 * `nginx -t` fail, and nginx then refuses to reload at all: every site on the
 * host serves the webserver's own 404 page while each deploy still reports
 * itself reachable through the webserver. Two such confs were found on the test
 * host, both from accounts the deploy harness had deleted.
 */
class WebserverPruneDomainsTest extends TestCase
{
    private string $dir;

    /** @var list<string> every domain the pruner actually asked to delete */
    private array $removed = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->removed = [];
        $this->dir = sys_get_temp_dir() . '/vhosts-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
        parent::tearDown();
    }

    private function vhost(string $name): void
    {
        file_put_contents($this->dir . '/' . $name . '.conf', "server {}\n");
    }

    /**
     * The real webserver class, with only the filesystem and the rm stubbed:
     * `pruneDomainConfigs()` itself is the production one.
     */
    private function webserver(): NginxProxy
    {
        return new class (new System(), $this->dir, $this->removed) extends NginxProxy {
            /** @param list<string> $removed */
            public function __construct(System $system, private string $dir, private array &$removed)
            {
                parent::__construct($system);
            }

            public function domainsConfigsDirPath(): string
            {
                return $this->dir;
            }

            public function deleteDomainConfig(string $domainName): void
            {
                $this->removed[] = $domainName;
            }
        };
    }

    public function test_a_vhost_for_a_deleted_domain_is_pruned(): void
    {
        $this->vhost('keep-one.test');
        $this->vhost('keep-two.test');
        $this->vhost('gone.test');
        // The engine's own files in the same directory are not per-domain
        // vhosts and must never be candidates.
        $this->vhost('app-lite');
        $this->vhost('proxy-rule-80_wildcard');
        $this->vhost('stream');

        $pruned = $this->webserver()->pruneDomainConfigs(['keep-one.test', 'keep-two.test']);

        $this->assertSame(['gone.test'], $pruned);
        $this->assertSame(['gone.test'], $this->removed);
    }

    /**
     * The ordinary case on every rebuild: the directory already agrees with the
     * database. If this pruned anything it would be the thing deleting a live
     * site's vhost.
     */
    public function test_a_directory_that_matches_the_database_prunes_nothing(): void
    {
        $this->vhost('one.test');
        $this->vhost('two.test');
        $this->vhost('app-lite');

        $this->assertSame([], $this->webserver()->pruneDomainConfigs(['one.test', 'two.test']));
        $this->assertSame([], $this->removed);
    }

    /** No domains at all -- a host whose last account was deleted. */
    public function test_every_domain_config_is_pruned_when_no_domain_exists(): void
    {
        $this->vhost('last.test');
        $this->vhost('app-lite');

        $this->assertSame(['last.test'], $this->webserver()->pruneDomainConfigs([]));
    }

    /** A directory that is not there is not an error. */
    public function test_a_missing_directory_prunes_nothing(): void
    {
        $webserver = new class (new System()) extends NginxProxy {
            public function __construct(System $system)
            {
                parent::__construct($system);
            }

            public function domainsConfigsDirPath(): string
            {
                return '/nonexistent/vhosts-' . bin2hex(random_bytes(4));
            }
        };

        $this->assertSame([], $webserver->pruneDomainConfigs(['anything.test']));
    }
}
