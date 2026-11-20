<?php

namespace Tests\Integration\System\Privileged\Scripts;

use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootScripts;
use App\System\Privileged\Scripts\ImagePipelines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

class ImagePipelinesTest extends TestCase
{
    private const IMAGE = 'panelalpha/php:8.3-pa1';

    private const LOCK = '/tmp/panelalpha-cache-registry.lock';

    private const LINES = '/usr/local/lib/panelalpha/pa-root lines.';

    private const ACCOUNT_DOCKER = '/usr/local/lib/panelalpha/pa-root account-docker.';

    public function test_a_host_build_quotes_the_dockerfile_and_skips_an_image_the_host_has(): void
    {
        $dockerfile = "FROM php:8.3\nRUN echo 'x'";
        $stdin = "printf '%s' 'FROM php:8.3\nRUN echo '\\''x'\\''' | ";

        $this->assertSame(
            self::LINES.'inspectImage -- '.self::IMAGE.' >/dev/null 2>&1 || { '
                .$stdin.self::LINES.'buildFromStdin -- '.self::IMAGE.' pull; }',
            (new ImagePipelines)->hostBuild(self::IMAGE, $dockerfile, null, false, true)
        );
    }

    public function test_a_rebuild_tries_the_published_copy_first_and_builds_without_pulling(): void
    {
        $script = (new ImagePipelines)->hostBuild(self::IMAGE, 'FROM php', 'ghcr.io/panelalpha/php:8.3-pa1', true, false);

        $this->assertStringStartsWith('{ '.self::LINES.'pullQuietly -- ghcr.io/panelalpha/php:8.3-pa1 >/dev/null 2>&1 && { if [ ', $script);
        $this->assertStringContainsString('= '.hash('sha256', 'FROM php').' ];', $script);
        $this->assertStringContainsString(self::LINES.'tag -- ghcr.io/panelalpha/php:8.3-pa1 '.self::IMAGE, $script);
        $this->assertStringEndsWith(
            "} || { printf '%s' 'FROM php' | ".self::LINES.'buildFromStdin -- '.self::IMAGE." ''; }",
            $script
        );
    }

    public function test_a_publish_pushes_under_the_push_lock(): void
    {
        $this->assertSame(
            '( touch '.self::LOCK.' 2>/dev/null; exec 9<'.self::LOCK.' && flock -s 9 && { '
                .self::LINES.'tag -- node:22 '.TestNetwork::LOOPBACK.':5000/node:22 && '.self::LINES.'pushQuietly -- '.TestNetwork::LOOPBACK.':5000/node:22 >/dev/null; } )',
            (new ImagePipelines)->hostPublish('node:22')
        );
    }

    public function test_a_load_from_the_host_pulls_through_the_accounts_own_daemon(): void
    {
        $script = (new ImagePipelines)->loadFromHost('demo', self::IMAGE);
        $pullRef = 'panelalpha-cache-registry:5000/'.self::IMAGE;

        $this->assertStringContainsString(
            self::ACCOUNT_DOCKER.'pull -- demo '.$pullRef." '' >/dev/null"
                .' && '.self::ACCOUNT_DOCKER.'tag -- demo '.$pullRef.' '.self::IMAGE
                .' && { '.self::ACCOUNT_DOCKER."removeImages -- demo '' ".$pullRef.' >/dev/null 2>&1 || true; }',
            $script
        );
        $this->assertStringContainsString("echo 'panelalpha-cache-registry or its writer is not running' >&2", $script);
        $this->assertStringContainsString(self::LINES.'pushQuietly -- '.TestNetwork::LOOPBACK.':5000/'.self::IMAGE, $script);
    }

    /** @return iterable<string, array{callable(): mixed}> */
    public static function refused(): iterable
    {
        yield 'build tag that is an option' => [fn () => (new RootScripts)->buildImageOnHost('--network=host', 'FROM php', null, true)];
        yield 'build of a second command' => [fn () => (new RootScripts)->buildImageOnHost('x;id', 'FROM php', null, true)];
        yield 'published copy that is an option' => [fn () => (new RootScripts)->buildImageOnHost(self::IMAGE, 'FROM php', '--all-tags', true)];
        yield 'background build with a space' => [fn () => (new RootScripts)->buildImageOnHostInBackground('a b', 'FROM php', null)];
        yield 'rebuild of uppercase' => [fn () => (new RootScripts)->rebuildImageOnHost('Panelalpha/php:1', 'FROM php', null)];
        yield 'load into an account named like a path' => [fn () => (new RootScripts)->loadImageFromHost('../root', self::IMAGE)];
        yield 'load into an account that is an option' => [fn () => (new RootScripts)->loadImageFromHost('--all', self::IMAGE)];
        yield 'load of an option' => [fn () => (new RootScripts)->loadImageFromHost('demo', '--quiet')];
        yield 'publish of a layout with a colon' => [fn () => (new RootScripts)->publishBuildCache('/w/out:/etc', TestNetwork::LOOPBACK.':5000/a:b')];
        yield 'publish of a ref with a quote' => [fn () => (new RootScripts)->publishBuildCache('/w/out', TestNetwork::LOOPBACK.":5000/a'b")];
        yield 'host publish of an option' => [fn () => (new ImagePipelines)->hostPublish('-q')];
    }

    /** @param  callable(): mixed  $build */
    #[DataProvider('refused')]
    public function test_a_value_a_command_could_misread_builds_nothing(callable $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }
}
