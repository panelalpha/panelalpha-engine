<?php

namespace Tests\Integration\Deploy;

use App\Lib\Deploy\Compose\ComposeHarden;
use PHPUnit\Framework\TestCase;

class ComposeHardenTest extends TestCase
{
    public function test_extract_runtime_sidecars_keeps_datastores_drops_dev_app(): void
    {
        $path = sys_get_temp_dir() . '/compose-sidecars-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($path, <<<'YAML'
services:
  laravel.test:
    build:
      context: ./docker/8.5
      args:
        WWWGROUP: '${WWWGROUP}'
    image: sail-8.5/app
    volumes:
      - '.:/var/www/html'
  mysql:
    image: 'mysql:8.4'
    environment:
      MYSQL_DATABASE: '${DB_DATABASE}'
      MYSQL_USER: '${DB_USERNAME}'
      MYSQL_PASSWORD: '${DB_PASSWORD}'
    ports:
      - '3306:3306'
    volumes:
      - 'sail-mysql:/var/lib/mysql'
  redis:
    image: 'redis:alpine'
  typesense:
    image: 'typesense/typesense:27.1'
    environment:
      TYPESENSE_API_KEY: '${TYPESENSE_API_KEY:-xyz}'
  mailpit:
    image: 'axllent/mailpit:latest'
volumes:
  sail-mysql:
    driver: local
YAML
        );
        try {
            $result = ComposeHarden::extractRuntimeSidecars($path);

            $this->assertArrayHasKey('mysql', $result['services']);
            $this->assertArrayHasKey('redis', $result['services']);
            $this->assertArrayHasKey('typesense', $result['services']);
            $this->assertArrayNotHasKey('laravel.test', $result['services']);
            $this->assertArrayNotHasKey('mailpit', $result['services']);
            $this->assertArrayNotHasKey('ports', $result['services']['mysql']);
            $this->assertSame('mysql:8.4', $result['services']['mysql']['image']);
            $this->assertSame('app', $result['services']['mysql']['environment']['MYSQL_DATABASE']);
            $this->assertSame('512m', $result['services']['mysql']['mem_limit']);
            $this->assertSame('%', $result['services']['mysql']['environment']['MYSQL_ROOT_HOST']);
            // The catalogue's healthcheck reaches the service. Its exact
            // shape is MysqlHealthcheckTest's subject -- asserting the literal
            // here made a deliberate fix to it read as a regression.
            $this->assertSame('CMD-SHELL', $result['services']['mysql']['healthcheck']['test'][0]);
            $this->assertStringContainsString(
                'ping',
                $result['services']['mysql']['healthcheck']['test'][1]
            );
            $this->assertSame('mysql', $result['env']['DB_CONNECTION']);
            $this->assertSame('mysql', $result['env']['DB_HOST']);
            $this->assertSame('app', $result['env']['DB_DATABASE']);
            $this->assertSame('redis', $result['env']['REDIS_HOST']);
            $this->assertSame('redis://redis:6379/0', $result['env']['REDIS_URL']);
            $this->assertSame('typesense', $result['env']['TYPESENSE_HOST']);
            $this->assertSame('true', $result['env']['TYPESENSE_ENABLED']);
            $this->assertSame('xyz', $result['env']['TYPESENSE_API_KEY']);
            $this->assertArrayHasKey('sail-mysql', $result['volumes']);
        } finally {
            unlink($path);
        }
    }
    /** Docs' `createbuckets`: yhub waits for it to finish, the backend only for it to start; it exits 0 and must stay down. */
    public function test_a_job_waited_on_to_finish_by_one_service_and_to_start_by_another_is_not_restarted(): void
    {
        $compose = ['services' => [
            'minio' => ['image' => 'minio/minio', 'healthcheck' => ['test' => ['CMD', 'mc', 'ready', 'local']]],
            'createbuckets' => [
                'image' => 'pgsty/mc',
                'depends_on' => ['minio' => ['condition' => 'service_healthy']],
                'entrypoint' => 'sh -c "/usr/bin/mc mb --ignore-existing impress/impress-media-storage && exit 0;"',
            ],
            'backend' => ['image' => 'acme/backend', 'depends_on' => ['createbuckets' => ['condition' => 'service_started']]],
            'yhub' => [
                'image' => 'acme/yhub',
                'ports' => ['3002:3002'],
                'depends_on' => ['createbuckets' => ['condition' => 'service_completed_successfully']],
            ],
        ]];

        $services = ComposeHarden::apply($compose)['services'];

        $this->assertSame(['createbuckets'], ComposeHarden::oneShotServices($compose));
        $this->assertSame('no', $services['createbuckets']['restart']);
        $this->assertSame('unless-stopped', $services['minio']['restart']);
    }

    /** One service needing it healthy says it keeps running, whatever another waits for. */
    public function test_a_service_waited_on_to_finish_and_to_be_healthy_is_not_a_one_shot(): void
    {
        $compose = ['services' => [
            'db' => ['image' => 'postgres:17', 'healthcheck' => ['test' => ['CMD', 'pg_isready']]],
            'seed' => ['image' => 'acme/seed', 'depends_on' => ['db' => ['condition' => 'service_completed_successfully']]],
            'app' => ['image' => 'acme/app', 'ports' => ['80:80'], 'depends_on' => ['db' => ['condition' => 'service_healthy']]],
        ]];

        $this->assertNotContains('db', ComposeHarden::oneShotServices($compose));
        $this->assertSame('unless-stopped', ComposeHarden::apply($compose)['services']['db']['restart']);
    }

    /** pgconsole: `remount-ro …/proc/cpuinfo: operation not permitted` while compose copied its `configs:` content in. */
    public function test_lxcfs_binds_leave_the_daemon_nothing_to_remount_when_compose_copies_a_config_in(): void
    {
        $result = ComposeHarden::applyReporting(
            [
                'services' => ['pgconsole' => ['image' => 'pgplex/pgconsole', 'configs' => [['source' => 'c', 'target' => '/etc/pgconsole.toml']]]],
                'configs' => ['c' => ['content' => "[[connections]]\n"]],
            ],
            procFiles: ['cpuinfo', 'meminfo']
        )['compose'];

        $proc = array_values(array_filter(
            $result['services']['pgconsole']['volumes'],
            static fn (mixed $volume): bool => is_array($volume) && str_starts_with((string) $volume['target'], '/proc/')
        ));
        $this->assertCount(2, $proc);
        foreach ($proc as $volume) {
            $this->assertArrayNotHasKey('read_only', $volume);
        }
    }

}
