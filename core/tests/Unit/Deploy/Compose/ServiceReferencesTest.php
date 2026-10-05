<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceReferences;
use PHPUnit\Framework\TestCase;

class ServiceReferencesTest extends TestCase
{
    public function test_every_dependency_form_makes_a_service_needed(): void
    {
        $services = [
            'web' => [
                'image' => 'acme/web',
                'depends_on' => ['listed'],
                'links' => ['linked:alias'],
                'volumes_from' => ['data:ro', 'container:outside'],
            ],
            'worker' => [
                'image' => 'acme/web',
                'depends_on' => ['mapped' => ['condition' => 'service_healthy']],
                'network_mode' => 'service:vpn',
            ],
            'listed' => ['image' => 'a'],
            'linked' => ['image' => 'a'],
            'data' => ['image' => 'a'],
            'mapped' => ['image' => 'a'],
            'vpn' => ['image' => 'a'],
            'outside' => ['image' => 'a'],
            'alone' => ['image' => 'a'],
        ];

        $this->assertSame(['listed', 'linked', 'data', 'mapped', 'vpn'], ServiceReferences::needed($services));
    }

    public function test_a_service_named_as_a_host_in_another_ones_environment_is_needed(): void
    {
        $services = [
            'app' => ['image' => 'acme/app', 'environment' => [
                'DB_HOST=db',
                'REDIS_URL=redis://cache:6379',
                'DATABASE_URL=postgres://u:p@pg/x',
                'PGHOST=pg2',
                'MONGO_URL=mongodb://m1:27017,m2:27017/app',
                'MEILI_ADDR=${MEILI_ADDR:-meili}:7700',
            ]],
            'api' => ['image' => 'acme/api', 'environment' => [
                'SEARCH_URL' => 'http://SearXNG:8080/search',
                'SANDBOX' => 'http://terrarium:8080',
            ]],
            'db' => ['image' => 'mysql'],
            'cache' => ['image' => 'redis'],
            'pg' => ['image' => 'postgres'],
            'pg2' => ['image' => 'postgres'],
            'm1' => ['image' => 'mongo'],
            'm2' => ['image' => 'mongo'],
            'meili' => ['image' => 'getmeili/meilisearch'],
            'search' => ['image' => 'searxng/searxng', 'container_name' => 'searxng'],
            'sandbox' => ['image' => 'acme/sandbox', 'networks' => ['default' => ['aliases' => ['terrarium']]]],
        ];

        $this->assertSame(
            ['db', 'cache', 'pg', 'pg2', 'm1', 'm2', 'meili', 'search', 'sandbox'],
            ServiceReferences::needed($services)
        );
    }

    /** A name inside another word, in a URL's path, or under a key that is not a host, does not count. */
    public function test_only_whole_host_tokens_count(): void
    {
        $services = [
            'app' => ['image' => 'acme/app', 'environment' => [
                'DB_HOST' => 'dbhost',
                'DATABASE_URL' => 'postgres://u:p@pgpool/x',
                'CALLBACK_URL' => 'http://web.example.com/cache',
                'DB_CONNECTION' => 'mysql',
                'CACHE_DRIVER' => 'redis',
                'APP_NAME' => 'search',
                'REDIS_HOST' => 'my-redis',
            ]],
            'db' => ['image' => 'mysql'],
            'pg' => ['image' => 'postgres'],
            'web' => ['image' => 'nginx'],
            'cache' => ['image' => 'redis'],
            'mysql' => ['image' => 'mysql'],
            'redis' => ['image' => 'redis'],
            'search' => ['image' => 'meilisearch'],
        ];

        $this->assertSame([], ServiceReferences::needed($services));
    }

    public function test_a_service_naming_itself_does_not_need_itself(): void
    {
        $services = [
            'web' => ['image' => 'acme/web', 'environment' => ['HOSTNAME=web', 'APP_URL=http://web:3000']],
            'helper' => ['image' => 'wordpress:cli', 'container_name' => 'wpcli', 'environment' => ['WORDPRESS_DB_HOST=wpcli']],
        ];

        $this->assertSame([], ServiceReferences::needed($services));
    }

    public function test_the_hosts_an_environment_names(): void
    {
        $this->assertSame(['db', 'cache'], ServiceReferences::hostsIn([
            'DB_HOST' => 'DB',
            'REDIS_URL' => 'redis://:secret@cache:6379/0',
            'DB_PORT' => 3306,
            'GREETING' => 'hello db',
        ]));
        $this->assertSame(['a', 'b'], ServiceReferences::hostsIn(['ES_HOSTS=a:9200, b:9200']));
        $this->assertSame([], ServiceReferences::hostsIn(['DB_HOST' => null, 'X_URL' => '[::1]:80']));
        $this->assertSame([], ServiceReferences::hostsIn(null));
    }
}
