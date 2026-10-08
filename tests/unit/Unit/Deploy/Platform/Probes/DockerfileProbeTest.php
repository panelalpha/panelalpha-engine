<?php

namespace Tests\Unit\Deploy\Platform\Probes;

use App\Lib\Deploy\Platform\Probes\DockerfileProbe;

/**
 * Is there a Dockerfile here the repository means to be built?
 *
 * "Is there a file called Dockerfile" is the easy half. The half that
 * matters is the Laravel Sail shape: a Dockerfile that maps the developer's
 * own uid and gid through build args. Building it in a tenant account
 * produces a container owned by whoever happened to run it, so the engine
 * has to look past the name and read the file.
 */
class DockerfileProbeTest extends ProbeTestCase
{
    private function probe(): DockerfileProbe
    {
        return new DockerfileProbe();
    }

    public function test_a_root_dockerfile_is_found(): void
    {
        $this->write('Dockerfile', "FROM node:20\nCMD [\"node\", \"server.js\"]\n");

        $this->assertSame(['dockerfile' => 'Dockerfile'], $this->probe()->evaluate($this->context()));
    }

    public function test_an_exposed_port_is_contributed(): void
    {
        $this->write('Dockerfile', "FROM node:20\nEXPOSE 8080\nCMD [\"node\", \"server.js\"]\n");

        $this->assertSame(
            ['dockerfile' => 'Dockerfile', 'port_hint' => 8080],
            $this->probe()->evaluate($this->context())
        );
    }

    public function test_the_first_exposed_port_wins(): void
    {
        // Multi-port images expose the HTTP port first by convention; the
        // metrics or debug port that follows is not what the proxy should
        // be pointed at.
        $this->write('Dockerfile', "FROM app\nEXPOSE 3000\nEXPOSE 9229\n");

        $this->assertSame(3000, $this->probe()->evaluate($this->context())['port_hint']);
    }

    public function test_a_suffixed_dockerfile_is_found(): void
    {
        $this->write('Dockerfile.prod', "FROM node:20\n");

        $this->assertSame(['dockerfile' => 'Dockerfile.prod'], $this->probe()->evaluate($this->context()));
    }

    public function test_a_nested_dockerfile_is_found(): void
    {
        $this->write('docker/Dockerfile', "FROM node:20\n");

        $this->assertSame(['dockerfile' => 'docker/Dockerfile'], $this->probe()->evaluate($this->context()));
    }

    public function test_the_root_dockerfile_beats_a_nested_one(): void
    {
        $this->write('Dockerfile', "FROM node:20\n");
        $this->write('docker/Dockerfile', "FROM node:18\n");

        $this->assertSame('Dockerfile', $this->probe()->evaluate($this->context())['dockerfile']);
    }

    public function test_a_dockerfile_that_maps_the_hosts_uid_is_not_deployable(): void
    {
        // The Laravel Sail shape. Nothing on the tenant host supplies WWWUSER,
        // so the build either fails or bakes in whichever uid ran it.
        $this->write('Dockerfile', <<<'DOCKER'
        FROM ubuntu:22.04
        ARG WWWGROUP
        ARG WWWUSER
        RUN groupadd --force -g $WWWGROUP sail
        RUN useradd -ms /bin/bash --no-user-group -g $WWWGROUP -u $WWWUSER sail
        DOCKER);

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }

    public function test_a_defaulted_uid_arg_is_deployable(): void
    {
        // Same shape, but the Dockerfile says what to use when nobody passes
        // one, so it builds standalone.
        $this->write('Dockerfile', <<<'DOCKER'
        FROM ubuntu:22.04
        ARG PUID=1000
        RUN adduser --uid $PUID app
        DOCKER);

        $this->assertSame(['dockerfile' => 'Dockerfile'], $this->probe()->evaluate($this->context()));
    }

    public function test_a_uid_variable_without_a_user_is_not_the_sail_shape(): void
    {
        // PUID passed to an entrypoint is normal for images that drop
        // privileges at runtime — it is only a workstation file when the
        // build itself creates the user.
        $this->write('Dockerfile', "FROM alpine\nENV PUID=\${PUID}\n");

        $this->assertSame(['dockerfile' => 'Dockerfile'], $this->probe()->evaluate($this->context()));
    }

    public function test_no_dockerfile_is_no_match(): void
    {
        $this->write('package.json', '{}');

        $this->assertFalse($this->probe()->evaluate($this->context()));
    }
}
