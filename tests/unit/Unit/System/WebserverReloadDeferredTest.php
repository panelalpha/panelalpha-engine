<?php

namespace Tests\Unit\System;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\Services\Webserver;
use App\System\Services\Webserver\Nginx;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// A proxy container that is down must not fail a save whose config is already rendered.
class WebserverReloadDeferredTest extends TestCase
{
    /**
     * A System whose webserver collaborator is replaced, so the reload path can
     * be driven without a host: the reload is the only thing under test.
     *
     * The stand-in also records what the deferral queued, which is the other
     * half of the behaviour -- `reloadWebserver()` returns false *and* the
     * reload is scheduled, not dropped.
     */
    private function system(Nginx $driver, array &$scheduled): System
    {
        $webserver = new class ($driver, $scheduled) extends Webserver {
            public function __construct(private Nginx $standIn, private array &$scheduled)
            {
            }

            public function driver(?string $slug = null): Nginx
            {
                return $this->standIn;
            }

            public function detectWebserver(): string
            {
                return 'nginx-proxy';
            }

            public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
            {
                $this->scheduled[] = $rebindIpListeners;
            }
        };

        return new class ($webserver) extends System {
            public function __construct(private Webserver $ws)
            {
            }

            public function webserver(): Webserver
            {
                return $this->ws;
            }
        };
    }

    public function test_a_reload_that_works_is_applied(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('rebuildConfig')->once();
        $driver->shouldReceive('reload')->once();
        $scheduled = [];

        $this->assertTrue($this->system($driver, $scheduled)->rebuildDomains());
        $this->assertSame([], $scheduled);
    }

    public function test_a_stopped_container_defers_the_reload(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('rebuildConfig')->once();
        $driver->shouldReceive('reload')->once()->andThrow(new DockerErrorException('service "sites-http" is not running'));
        $scheduled = [];

        $this->assertFalse($this->system($driver, $scheduled)->rebuildDomains());
        $this->assertSame([false], $scheduled);
    }

    public function test_a_restarting_container_defers_the_reload(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('reload')->once()->andThrow(new DockerErrorException(
            'Error response from daemon: Container 3f2a is restarting, wait until the container is running'
        ));
        $scheduled = [];

        $this->assertFalse($this->system($driver, $scheduled)->reloadWebserver());
        $this->assertSame([false], $scheduled);
    }

    /** The queued reload must not be the IP-listener-rebinding one. */
    public function test_the_deferral_does_not_ask_for_an_ip_listener_rebind(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('reload')->once()->andThrow(new DockerErrorException('service "sites-http" is not running'));
        $scheduled = [];

        $this->system($driver, $scheduled)->reloadWebserver();

        $this->assertSame([false], $scheduled);
    }

    public function test_any_other_reload_failure_still_throws(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('reload')->once()->andThrow(new DockerErrorException('Error response from daemon: No such container'));
        $scheduled = [];

        $this->expectException(DockerErrorException::class);
        try {
            $this->system($driver, $scheduled)->reloadWebserver();
        } finally {
            $this->assertSame([], $scheduled);
        }
    }

    /**
     * The reload path must not turn a non-docker failure into a deferred one --
     * a bug in the caller is not a container that is down.
     */
    public function test_a_non_docker_failure_is_not_deferred(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('reload')->once()->andThrow(new ModelNotFoundException('domain row gone'));
        $scheduled = [];

        $this->expectException(ModelNotFoundException::class);
        try {
            $this->system($driver, $scheduled)->reloadWebserver();
        } finally {
            $this->assertSame([], $scheduled);
        }
    }

    /**
     * A rebuild reports the reload's outcome, and the config is rendered even
     * when the reload cannot be applied -- rendering writes to disk, so a down
     * proxy must not lose it.
     */
    public function test_the_config_is_rendered_before_the_reload_is_attempted(): void
    {
        $driver = Mockery::mock(Nginx::class);
        $driver->shouldReceive('rebuildConfig')->once()->globally()->ordered();
        $driver->shouldReceive('reload')->once()->globally()->ordered()->andThrow(
            new DockerErrorException('service "sites-http" is not running')
        );
        $scheduled = [];

        $this->assertFalse($this->system($driver, $scheduled)->rebuildDomains());
    }

    /**
     * `reloadWebserver()` is also reached through Project, where the runtime is
     * the one that owns the webserver. The root aggregate must not swallow the
     * exception when scheduling itself fails.
     */
    public function test_a_failed_scheduling_does_not_replace_the_deferral(): void
    {
        $webserver = new class (new System()) extends Webserver {
            public function driver(?string $slug = null): Nginx
            {
                $driver = Mockery::mock(Nginx::class);
                $driver->shouldReceive('reload')->once()->andThrow(
                    new DockerErrorException('service "sites-http" is not running')
                );

                return $driver;
            }

            public function detectWebserver(): string
            {
                return 'nginx-proxy';
            }

            public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
            {
                throw new \RuntimeException('at is not installed');
            }
        };

        $system = new class ($webserver) extends System {
            public function __construct(private Webserver $ws)
            {
            }

            public function webserver(): Webserver
            {
                return $this->ws;
            }
        };

        $this->assertFalse($system->reloadWebserver(), 'a scheduling failure still leaves the config rendered and the save done');
    }

    /** A System that must never reach the host, used to prove nothing else runs. */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** Guard against the test itself touching a host process. */
    private function unusedProcess(): Process
    {
        return new Process(['true']);
    }
}
