<?php

namespace Tests\Unit\Deploy\CacheManager;

use App\Lib\Deploy\CacheManager\CacheRegistry;
use PHPUnit\Framework\TestCase;

/**
 * How the registry client reads an untagged reference and an answer other than 200.
 */
class CacheRegistryAnswersTest extends TestCase
{
    private const BASE = 'http://registry.test';

    /**
     * @param  array<string, array{status: int, headers?: array<string, string>, body?: string}>  $routes  "METHOD url"
     * @param  list<string>  $sent
     */
    private function registry(array $routes, array &$sent = []): CacheRegistry
    {
        return new CacheRegistry(
            static function (string $method, string $url) use ($routes, &$sent): ?array {
                $sent[] = "{$method} {$url}";
                $route = $routes["{$method} {$url}"] ?? null;

                return $route === null ? null : $route + ['headers' => [], 'body' => ''];
            },
            self::BASE
        );
    }

    public function test_a_reference_without_a_tag_is_its_latest(): void
    {
        $sent = [];
        $registry = $this->registry([
            'HEAD '.self::BASE.'/v2/panelalpha/php/manifests/latest' => ['status' => 200, 'headers' => ['docker-content-digest' => 'sha256:a']],
            'HEAD '.self::BASE.'/v2/registry.test:5000/app/manifests/latest' => ['status' => 200, 'headers' => ['docker-content-digest' => 'sha256:b']],
        ], $sent);

        $this->assertSame('sha256:a', $registry->digest('panelalpha/php'));
        $this->assertSame('sha256:b', $registry->digest('registry.test:5000/app'));
    }

    public function test_a_catalogue_that_answers_an_error_is_unreadable(): void
    {
        $registry = $this->registry([
            'GET '.self::BASE.'/v2/_catalog?n=1000' => ['status' => 500, 'body' => '{"repositories":["node"]}'],
        ]);

        $this->assertNull($registry->refs());
        $this->assertSame([], $registry->emptyRepositories());
    }

    public function test_a_repository_whose_tags_could_not_be_read_is_not_empty(): void
    {
        $registry = $this->registry([
            'GET '.self::BASE.'/v2/_catalog?n=1000' => ['status' => 200, 'body' => '{"repositories":["failing","silent","missing","garbled","keyless","untagged","cleared"]}'],
            'GET '.self::BASE.'/v2/failing/tags/list' => ['status' => 500, 'body' => '{"tags":null}'],
            'GET '.self::BASE.'/v2/missing/tags/list' => ['status' => 404, 'body' => '{"errors":[{"code":"NAME_UNKNOWN"}]}'],
            'GET '.self::BASE.'/v2/garbled/tags/list' => ['status' => 200, 'body' => '<html>'],
            'GET '.self::BASE.'/v2/keyless/tags/list' => ['status' => 200, 'body' => '{"name":"keyless"}'],
            'GET '.self::BASE.'/v2/untagged/tags/list' => ['status' => 200, 'body' => '{"name":"untagged","tags":null}'],
            'GET '.self::BASE.'/v2/cleared/tags/list' => ['status' => 200, 'body' => '{"name":"cleared","tags":[]}'],
        ]);

        $this->assertSame(['untagged', 'cleared'], $registry->emptyRepositories());
    }
}
