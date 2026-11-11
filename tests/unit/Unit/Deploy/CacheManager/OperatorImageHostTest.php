<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use App\Lib\Deploy\CacheManager\OperatorImageHost;
use App\Lib\Deploy\CacheManager\RegistryImageConfig;
use App\Lib\Deploy\Dind\DindImageStore;
use App\System;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

/**
 * The host side of an operator image: how it is built, and which registry
 * manifest removing it may delete.
 */
class OperatorImageHostTest extends TestCase
{
    public function test_a_build_has_no_context_and_a_refresh_no_cache(): void
    {
        $command = OperatorImageHost::buildCommand('whmcs-dev-base:php8.3', "FROM debian:12\nRUN echo '\$HOME'\n", ['PHP' => '8.3'], true);

        $this->assertStringStartsWith("printf %s 'FROM debian:12\nRUN echo '\\''\$HOME'\\''\n' | '".LocalRoot::CLIENT."' 'docker.buildFromStdin'", $command);
        $this->assertSame(['whmcs-dev-base:php8.3', 'no-cache', '1', 'PHP', '8.3'], LocalRoot::values($this->piped($command)));
        $this->assertSame([], LocalRoot::plain($this->piped($command)));
        $this->assertSame(['a:1', '', '0'], LocalRoot::values($this->piped(OperatorImageHost::buildCommand('a:1', "FROM x\n", [], false))));
    }

    public function test_a_build_runs_the_dockerfile_it_was_given(): void
    {
        $dockerfile = "FROM debian:12\nRUN echo \"it's \$PATH\" > /x\n";
        $piped = shell_exec(str_replace(" | '".LocalRoot::CLIENT."'", ' | cat #', OperatorImageHost::buildCommand('a:1', $dockerfile, [], false)));

        $this->assertSame($dockerfile, $piped);
    }

    public function test_removing_deletes_its_manifest_and_garbage_collects(): void
    {
        $registry = new FakeRegistry(['whmcs-dev-base:php8.3' => 'sha256:a', 'whmcs-dev-base:php8.2' => 'sha256:b', 'other:1' => 'sha256:a']);
        $system = new GcRecordingSystem;

        $outcome = $this->host($system, $registry)->unpublish('whmcs-dev-base:php8.3');

        $this->assertSame('removed from the cache registry', $outcome);
        $this->assertSame(['DELETE whmcs-dev-base sha256:a'], $registry->deleted);
        $this->assertSame([DindImageStore::garbageCollectArgv()], $system->commands);
    }

    public function test_removing_the_last_tag_drops_the_now_empty_repository(): void
    {
        $registry = new FakeRegistry(['solo:1' => 'sha256:a', 'other:1' => 'sha256:b']);
        $system = new GcRecordingSystem;

        $this->host($system, $registry)->unpublish('solo:1');

        $this->assertSame([
            DindImageStore::garbageCollectArgv(),
            CacheRegistry::removeRepositoryArgv('solo'),
        ], $system->commands);
    }

    public function test_a_tag_sharing_its_content_with_a_sibling_is_kept(): void
    {
        $registry = new FakeRegistry(['base:1' => 'sha256:a', 'base:latest' => 'sha256:a']);

        $outcome = $this->host(new GcRecordingSystem, $registry)->unpublish('base:1');

        $this->assertStringStartsWith('kept in the cache registry', $outcome);
        $this->assertSame([], $registry->deleted);
    }

    public function test_a_tag_the_registry_does_not_hold_deletes_nothing(): void
    {
        $registry = new FakeRegistry(['other:1' => 'sha256:a']);

        $this->assertSame('not in the cache registry', $this->host(new GcRecordingSystem, $registry)->unpublish('base:1'));
        $this->assertSame([], $registry->deleted);
    }

    /**
     * The command the broker runs for the request a build line pipes into.
     *
     * @return list<string>
     */
    /** @return list<string> */
    private function piped(string $command): array
    {
        preg_match_all("/'([^']*)'/", substr($command, (int) strrpos($command, " | '".LocalRoot::CLIENT."'")), $words);

        return $words[1];
    }

    private function host(System $system, FakeRegistry $registry): OperatorImageHost
    {
        return new OperatorImageHost($system, new DindImageStore, $registry->client(), new RegistryImageConfig(static fn (): ?string => null));
    }
}

class GcRecordingSystem extends System
{
    /** @var list<string|list<string>> */
    public array $commands = [];

    public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
    {
        $this->commands[] = $cmd;

        return '';
    }
}

class FakeRegistry
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    private array $repositories;

    /**
     * @param  array<string, string>  $tags  ref => digest
     */
    public function __construct(private array $tags)
    {
        $this->repositories = array_values(array_unique(array_map(static fn (string $ref): string => explode(':', $ref)[0], array_keys($tags))));
    }

    public function client(): CacheRegistry
    {
        return new CacheRegistry(fn (string $method, string $url): ?array => $this->answer($method, $url), 'http://registry');
    }

    /**
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function answer(string $method, string $url): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '/v2/_catalog') {
            return ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['repositories' => $this->repositories])];
        }
        if (preg_match('#^/v2/(.+)/tags/list$#', $path, $m) === 1) {
            $tags = [];
            foreach (array_keys($this->tags) as $ref) {
                [$repository, $tag] = explode(':', $ref);
                if ($repository === $m[1]) {
                    $tags[] = $tag;
                }
            }

            return ['status' => 200, 'headers' => [], 'body' => (string) json_encode(['tags' => $tags === [] ? null : $tags])];
        }
        preg_match('#^/v2/(.+)/manifests/(.+)$#', $path, $m);
        if ($method === 'DELETE') {
            $this->deleted[] = "DELETE {$m[1]} {$m[2]}";
            foreach (array_keys($this->tags) as $ref) {
                if (explode(':', $ref)[0] === $m[1] && $this->tags[$ref] === $m[2]) {
                    unset($this->tags[$ref]);
                }
            }

            return ['status' => 202, 'headers' => [], 'body' => ''];
        }
        $digest = $this->tags["{$m[1]}:{$m[2]}"] ?? null;

        return $digest === null
            ? ['status' => 404, 'headers' => [], 'body' => '']
            : ['status' => 200, 'headers' => ['docker-content-digest' => $digest], 'body' => ''];
    }
}
