<?php

namespace Tests\Unit\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use PHPUnit\Framework\TestCase;

final class AccountDaemonTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    /** @var list<list<string>> */
    private array $asked = [];

    public function test_containers_come_back_in_the_order_asked_with_null_for_a_missing_one(): void
    {
        $daemon = $this->daemon(['[{"Id":"' . str_repeat('c', 64) . '","State":{"Running":true}},null]']);

        $found = $daemon->containers(['app', 'gone'], 15);

        $this->assertTrue($found[0]['State']['Running']);
        $this->assertNull($found[1]);
        $this->assertSame([self::CLIENT, 'account-docker.containers', '--', 'alice', 'app', 'gone'], $this->asked[0]);
    }

    public function test_nothing_asked_is_no_request(): void
    {
        $daemon = $this->daemon([]);

        $this->assertSame([], $daemon->containers([]));
        $this->assertSame([], $daemon->images([]));
        $daemon->removeImages([]);
        $this->assertSame([], $this->asked);
    }

    public function test_container_ids_are_the_full_ids_of_the_listed_rows(): void
    {
        $daemon = $this->daemon(['[{"Id":"' . str_repeat('a', 64) . '","Names":["/x"]},{"Id":"' . str_repeat('b', 64) . '"}]']);

        $ids = $daemon->containerIds(true, ['label' => ['com.docker.compose.project=p']]);

        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], $ids);
        $this->assertSame(['all', '1', 'label', 'com.docker.compose.project=p'], array_slice($this->asked[0], 4));
    }

    public function test_image_tags_are_the_rows_docker_images_prints(): void
    {
        $daemon = $this->daemon([json_encode([
            ['Id' => 'sha256:1', 'RepoTags' => ['node:22-bookworm-slim', 'docker.io/library/node:22'], 'RepoDigests' => ['node@sha256:aa']],
            ['Id' => 'sha256:2', 'RepoTags' => [], 'RepoDigests' => ['panelalpha-cache-registry:5000/php@sha256:bb', 'panelalpha-cache-registry:5000/php@sha256:cc']],
            ['Id' => 'sha256:3', 'RepoTags' => [], 'RepoDigests' => []],
            ['Id' => 'sha256:4', 'RepoTags' => ['<none>:<none>'], 'RepoDigests' => ['<none>@<none>']],
            ['Id' => 'sha256:5', 'RepoTags' => ['docker.io/acme/app:1'], 'RepoDigests' => null],
            ['Id' => 'sha256:6', 'RepoTags' => ['registry.example:5000/a/b']],
        ])]);

        $this->assertSame([
            'node:22-bookworm-slim',
            'node:22',
            'panelalpha-cache-registry:5000/php:<none>',
            '<none>:<none>',
            '<none>:<none>',
            'acme/app:1',
        ], $daemon->imageTags());
    }

    public function test_answers_and_has_image_never_throw(): void
    {
        $daemon = $this->daemon([new DockerErrorException('container alice is not running'), new \RuntimeException('no such image')]);

        $this->assertFalse($daemon->answers());
        $this->assertFalse($daemon->hasImage('node:22'));
    }

    public function test_a_failure_throws_what_system_threw(): void
    {
        $daemon = $this->daemon([new DockerErrorException('Error response from daemon: No such image: a:1')]);

        $this->expectException(DockerErrorException::class);

        $daemon->tag('a:1', 'b:1');
    }

    public function test_an_answer_that_is_no_json_list_throws(): void
    {
        $daemon = $this->daemon(['{"Id":"x"}']);

        $this->expectExceptionMessage('no JSON list');

        $daemon->images(['node:22']);
    }

    public function test_the_log_tail_is_the_broker_s_output_as_it_came(): void
    {
        $daemon = $this->daemon(["listening\nboom\n", "2026-11-10T08:00:00.000000001Z listening\n"]);

        $this->assertSame("listening\nboom\n", $daemon->logTail('app', 20, 15));
        $this->assertSame([self::CLIENT, 'account-docker.logTail', '--', 'alice', 'app', '20', ''], $this->asked[0]);
        $this->assertSame("2026-11-10T08:00:00.000000001Z listening\n", $daemon->logTail('app', 20, 15, true));
        $this->assertSame([self::CLIENT, 'account-docker.logTail', '--', 'alice', 'app', '20', 'timestamps'], $this->asked[1]);
    }

    public function test_a_volume_is_created_by_its_name_and_a_refusal_throws(): void
    {
        $daemon = $this->daemon(["project_db\n", new DockerErrorException('Error response from daemon: create a/b: invalid name')]);

        $daemon->createVolume('project_db', 120);

        $this->assertSame([self::CLIENT, 'account-docker.createVolume', '--', 'alice', 'project_db'], $this->asked[0]);
        $this->expectException(DockerErrorException::class);
        $daemon->createVolume('project_cache');
    }

    public function test_volumes_come_back_in_the_order_asked_with_null_for_a_missing_one(): void
    {
        $daemon = $this->daemon(['[{"Name":"project_db","Driver":"local","Labels":null},null]']);

        $found = $daemon->volumes(['project_db', 'gone']);

        $this->assertSame([['Name' => 'project_db', 'Driver' => 'local', 'Labels' => null], null], $found);
        $this->assertSame([self::CLIENT, 'account-docker.volumes', '--', 'alice', 'project_db', 'gone'], $this->asked[0]);
        $this->assertSame([], $daemon->volumes([]));
        $this->assertCount(1, $this->asked);
    }

    public function test_a_volume_the_daemon_does_not_have_fails_as_docker_volume_inspect_does(): void
    {
        $daemon = $this->daemon(['[{"Name":"project_db"}]', '[null]']);

        $this->assertSame(['Name' => 'project_db'], $daemon->volume('project_db'));
        try {
            $daemon->volume('project_gone');
            $this->fail('a missing volume was found');
        } catch (DockerErrorException $e) {
            $this->assertSame('Error response from daemon: get project_gone: no such volume', $e->getMessage());
        }
    }

    public function test_volume_names_are_every_listed_volume_s_name(): void
    {
        $daemon = $this->daemon([json_encode([
            ['Name' => 'project_db', 'Driver' => 'local'],
            ['Name' => str_repeat('ab', 32), 'Driver' => 'local'],
            ['Driver' => 'local'],
            ['Name' => 7],
            'project_odd',
        ]), '[]', '{"Volumes":[]}']);

        $this->assertSame(['project_db', str_repeat('ab', 32)], $daemon->volumeNames());
        $this->assertSame([self::CLIENT, 'account-docker.listVolumes', '--', 'alice'], $this->asked[0]);
        $this->assertSame([], $daemon->volumeNames());
        $this->expectExceptionMessage('no JSON list');
        $daemon->volumeNames();
    }

    public function test_the_build_cache_size_is_the_broker_s_total(): void
    {
        $daemon = $this->daemon(["5368709120\n", "0\n"]);

        $this->assertSame(5368709120, $daemon->buildCacheBytes());
        $this->assertSame(0, $daemon->buildCacheBytes());
        $this->assertSame([self::CLIENT, 'account-docker.buildCacheBytes', '--', 'alice'], $this->asked[0]);
    }

    public function test_a_build_cache_size_the_broker_could_not_give_is_null(): void
    {
        $daemon = $this->daemon(['', "12 MB\n", "-1\n", '{"BuildCache":[]}', new DockerErrorException('container alice is not running')]);

        for ($answer = 0; $answer < 5; $answer++) {
            $this->assertNull($daemon->buildCacheBytes());
        }
        $this->assertCount(5, $this->asked);
    }

    public function test_the_stack_is_its_project_s_running_containers_without_one_offs_by_name(): void
    {
        $daemon = $this->daemon([json_encode([
            ['Id' => str_repeat('b', 64), 'Names' => ['/shop-web-1']],
            ['Id' => str_repeat('a', 64), 'Names' => ['/shop-app-1']],
        ])]);

        $this->assertSame([str_repeat('a', 64), str_repeat('b', 64)], $daemon->stackContainerIds());
        $this->assertSame(['', '3', 'label', 'com.docker.compose.project=shop', 'label', 'com.docker.compose.config-hash', 'label', 'com.docker.compose.oneoff=False'], array_slice($this->asked[0], 4));
    }

    public function test_the_whole_stack_takes_stopped_and_one_off_containers_and_one_service_its_own(): void
    {
        $daemon = $this->daemon(['[]']);

        $this->assertSame([], $daemon->stackContainerIds(true, true, 'app'));
        $this->assertSame(['all', '3', 'label', 'com.docker.compose.project=shop', 'label', 'com.docker.compose.config-hash', 'label', 'com.docker.compose.service=app'], array_slice($this->asked[0], 4));
    }

    public function test_a_name_compose_refuses_fails_each_stack_lookup_before_the_daemon_is_asked(): void
    {
        $refused = new \InvalidArgumentException('invalid project name "My_App"');
        $daemon = $this->daemon([], $refused);

        foreach ([$daemon->stackContainers(...), $daemon->stackContainerIds(...), $daemon->stackPs(...)] as $lookup) {
            try {
                $lookup();
                $this->fail('a stack was looked up under a name compose refuses');
            } catch (\InvalidArgumentException $e) {
                $this->assertSame($refused, $e);
            }
        }
        $this->assertSame([], $this->asked);
    }

    public function test_the_stack_ps_is_the_rows_compose_prints(): void
    {
        $created = time() - 150;
        $daemon = $this->daemon([
            json_encode([
                [
                    'Id' => str_repeat('a', 64), 'Names' => ['/shop-app-1'], 'Image' => 'shop-app', 'Command' => 'docker-entrypoint.sh node server.js',
                    'Created' => $created, 'State' => 'running', 'Status' => 'Up 2 minutes', 'Labels' => ['com.docker.compose.project' => 'shop', 'com.docker.compose.service' => 'app'],
                    'Mounts' => [['Name' => 'shop_data', 'Source' => '/home/alice/docker/volumes/shop_data/_data', 'Driver' => 'local'], ['Source' => '/home/alice/project']],
                    'NetworkSettings' => ['Networks' => ['shop_default' => [], 'pash-tenants' => []]],
                ],
                [
                    'Id' => str_repeat('b', 64), 'Names' => ['/shop-db-1'], 'Image' => 'mariadb:11', 'Command' => 'docker-entrypoint.sh mariadbd',
                    'Created' => $created, 'State' => 'exited', 'Status' => 'Exited (1) 3 minutes ago', 'Labels' => ['com.docker.compose.service' => 'db'],
                ],
            ]),
            json_encode([
                ['State' => ['Status' => 'running', 'ExitCode' => 0, 'Health' => ['Status' => 'healthy']], 'NetworkSettings' => ['Ports' => [
                    '3000/tcp' => [['HostIp' => '0.0.0.0', 'HostPort' => '8000'], ['HostIp' => '::', 'HostPort' => '8000']],
                    '9229/tcp' => null,
                ]]],
                ['State' => ['Status' => 'exited', 'ExitCode' => 1, 'Health' => ['Status' => 'unhealthy']], 'NetworkSettings' => ['Ports' => []]],
            ]),
        ]);

        $rows = array_map(static fn (string $line): array => json_decode($line, true), explode("\n", trim($daemon->stackPs(true))));

        $this->assertSame([
            [
                'Command' => '"docker-entrypoint.s…"', 'CreatedAt' => gmdate('Y-m-d H:i:s', $created).' +0000 UTC', 'Engine' => '',
                'ExitCode' => 0, 'Health' => 'healthy', 'ID' => str_repeat('a', 12), 'Image' => 'shop-app',
                'Labels' => 'com.docker.compose.project=shop,com.docker.compose.service=app', 'LocalVolumes' => '1',
                'Mounts' => 'shop_data,/home/alice/pr…', 'Name' => 'shop-app-1', 'Names' => 'shop-app-1', 'Networks' => 'shop_default,pash-tenants',
                'Ports' => '9229/tcp, 0.0.0.0:8000->3000/tcp, [::]:8000->3000/tcp',
                'Project' => 'shop', 'Publishers' => [
                    ['URL' => '0.0.0.0', 'TargetPort' => 3000, 'PublishedPort' => 8000, 'Protocol' => 'tcp'],
                    ['URL' => '::', 'TargetPort' => 3000, 'PublishedPort' => 8000, 'Protocol' => 'tcp'],
                    ['URL' => '', 'TargetPort' => 9229, 'PublishedPort' => 0, 'Protocol' => 'tcp'],
                ],
                'RunningFor' => '2 minutes ago', 'Service' => 'app', 'Size' => '0B', 'State' => 'running', 'Status' => 'Up 2 minutes',
            ],
            [
                'Command' => '"docker-entrypoint.s…"', 'CreatedAt' => gmdate('Y-m-d H:i:s', $created).' +0000 UTC', 'Engine' => '',
                'ExitCode' => 1, 'Health' => '', 'ID' => str_repeat('b', 12), 'Image' => 'mariadb:11', 'Labels' => 'com.docker.compose.service=db',
                'LocalVolumes' => '0', 'Mounts' => '', 'Name' => 'shop-db-1', 'Names' => 'shop-db-1', 'Networks' => '', 'Ports' => '', 'Project' => '',
                'Publishers' => [], 'RunningFor' => '2 minutes ago', 'Service' => 'db', 'Size' => '0B', 'State' => 'exited',
                'Status' => 'Exited (1) 3 minutes ago',
            ],
        ], $rows);
        $this->assertSame(['account-docker.containers', '--', 'alice', str_repeat('a', 64), str_repeat('b', 64)], array_slice($this->asked[1], 1));
    }

    public function test_an_empty_stack_prints_nothing_and_asks_no_inspect(): void
    {
        $daemon = $this->daemon(['[]']);

        $this->assertSame('', $daemon->stackPs());
        $this->assertCount(1, $this->asked);
    }

    public function test_a_container_gone_between_the_list_and_its_inspect_fails_the_ps(): void
    {
        $daemon = $this->daemon([json_encode([['Id' => str_repeat('a', 64), 'Names' => ['/shop-app-1']]]), '[null]']);

        $this->expectException(DockerErrorException::class);

        $daemon->stackPs();
    }

    /** @param  list<string|\Throwable>  $answers */
    private function daemon(array $answers, ?\Throwable $refusedName = null): AccountDaemon
    {
        $system = $this->createStub(System::class);
        $system->method('exec')->willReturnCallback(function (array $argv) use (&$answers): string {
            $this->asked[] = $argv;
            $answer = array_shift($answers) ?? '';
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('alice');
        if ($refusedName === null) {
            $dind->method('userAppComposeProjectName')->willReturn('shop');
        } else {
            $dind->method('userAppComposeProjectName')->willThrowException($refusedName);
        }
        $dind->method('system')->willReturn($system);

        return new AccountDaemon($dind);
    }
}
