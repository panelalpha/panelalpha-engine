<?php

namespace Tests\Integration\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Models\Domain;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AccountDaemon;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\ShellOperations;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ComposePsFixture;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;

/**
 * What AppHealth answers, output for output: the probe scripts byte for byte, the parsed
 * port results, the restart, stop and blank-page checks, and every way check() can end.
 */
class AppHealthOutputsPinnedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const COMPOSE_PS_RESTARTING = '{"Name":"project-app-1","Service":"app","State":"restarting","Status":"Restarting (1) 2 seconds ago","ExitCode":1}';

    private const COMPOSE_PS_STOPPED = '{"Name":"project-app-1","Service":"app","State":"exited","Status":"Exited (0) 3 hours ago","ExitCode":0}';

    private const COMPOSE_PS_RUNNING = '{"Name":"project-app-1","Service":"app","State":"running","Status":"Up 3 hours","ExitCode":0}';

    /** What each output was before the split, loopback written as `<loopback>`. */
    private const PINS = [
        'probe 8080' => [
            'bytes' => 5931,
            'sha256' => '7a212f0be48adc8871784873fa0c7f6ecf559f510db06b46342e2996f712352d',
        ],
        'probe 8080,8443' => [
            'bytes' => 6163,
            'sha256' => '640629968ad0130e82f583161c3c491cea2d6c74812bcaa95729e7069b18fcbb',
        ],
        'probe 0,3000' => [
            'bytes' => 5937,
            'sha256' => '47b3337c938c6ac6ec2c12be19e7520130ecc77479a2cbaba723a36e7a8df3be',
        ],
        'path probe' => [
            'bytes' => 521,
            'sha256' => 'a49f2b39b580639a2a33c8e05105541c756189fc7dd02f29e53d61645b983073',
        ],
        'parsed' => [
            [
                'port' => 9000,
                'scheme' => 'https',
                'status' => 'fail',
                'http_code' => 502,
                'time' => 1.5,
                'detail' => 'HTTP 502',
                'body' => '',
                'path' => '/',
            ],
            [
                'port' => 8000,
                'scheme' => 'http',
                'status' => 'ok',
                'http_code' => 200,
                'time' => 0.012,
                'detail' => 'HTTP 200',
                'body' => '<p>hi</p>',
                'path' => '/login',
            ],
            [
                'port' => 8443,
                'scheme' => null,
                'status' => 'fail',
                'http_code' => null,
                'time' => null,
                'detail' => 'connection refused',
            ],
            [
                'port' => 7000,
                'scheme' => null,
                'status' => 'fail',
                'http_code' => null,
                'time' => null,
                'detail' => 'the probe returned no result for this port',
            ],
        ],
        'app loop' => [
            'id' => 'app-restart-looping',
            'group' => 'runtime',
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'The application is restarting, not running.',
            'detail' => 'Docker reports app (exited, last exit 1, restarted 1 time), project-worker-1 (restarting, last exit 3, restarted 7 times). It may answer between restarts, but it does not stay up.',
            'fix' => 'Read the container output; the reason it exits is there. A process that exits 0 is restarted too, e.g. a command ending in a shell with no TTY.',
            'evidence' => [
                'restarting' => [
                    'app (exited, last exit 1, restarted 1 time)',
                    'project-worker-1 (restarting, last exit 3, restarted 7 times)',
                ],
            ],
        ],
        'unknown app loop' => [
            'id' => 'app-restart-looping',
            'group' => 'runtime',
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'The application is restarting, not running.',
            'detail' => 'Docker reports project-worker-1 (restarting, last exit 3, restarted 7 times). It may answer between restarts, but it does not stay up.',
            'fix' => 'Read the container output; the reason it exits is there. A process that exits 0 is restarted too, e.g. a command ending in a shell with no TTY.',
            'evidence' => [
                'restarting' => [
                    'project-worker-1 (restarting, last exit 3, restarted 7 times)',
                ],
            ],
        ],
        'sidecar loop' => [
            'id' => 'app-restart-looping',
            'group' => 'runtime',
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'A backing service is restarting: db (restarting, last exit 1, restarted 3 times).',
            'detail' => 'The application itself is not restarting, but what it needs from db may not be there.',
            'fix' => 'Read the container output of db; the reason it exits is there.',
            'evidence' => [
                'restarting' => [
                    'db (restarting, last exit 1, restarted 3 times)',
                ],
            ],
        ],
        'stopped' => [
            'id' => 'app-stopped',
            'group' => 'runtime',
            'status' => 'fail',
            'severity' => 'error',
            'title' => 'The application container is stopped.',
            'detail' => 'Docker reports app (Exited (0) 3 hours ago), project-cache-1, a service. Nothing answered because it is not running.',
            'fix' => 'Start it again, or redeploy.',
            'evidence' => [
                'stopped' => [
                    'app (Exited (0) 3 hours ago)',
                    'project-cache-1',
                    'a service',
                ],
            ],
        ],
        'blank php' => [
            'serving' => 'blank_page',
            'checks' => [
                [
                    'id' => 'x',
                    'status' => 'fail',
                    'severity' => 'warning',
                ],
                [
                    'id' => 'app-blank-page',
                    'group' => 'php',
                    'status' => 'fail',
                    'severity' => 'error',
                    'title' => 'The front page answered 200 with an empty page.',
                    'detail' => 'http://<loopback>:8000/ returned no content.',
                    'fix' => 'Usually a PHP fatal error the application suppresses. Read the container output, or turn on the application\'s debug mode, to see it.',
                    'evidence' => [
                        'url' => 'http://<loopback>:8000/',
                        'http_code' => 200,
                    ],
                ],
            ],
        ],
        'blank outranked' => [
            'serving' => 'error_page',
            'checks' => [
                [
                    'id' => 'y',
                    'status' => 'fail',
                    'severity' => 'error',
                ],
                [
                    'id' => 'app-blank-page',
                    'group' => '_baseline',
                    'status' => 'fail',
                    'severity' => 'error',
                    'title' => 'The front page answered 200 with an empty page.',
                    'detail' => 'http://<loopback>:8000/ returned no content.',
                    'fix' => 'Read the container output to see why it sends nothing. A route meant to send nothing answers 204.',
                    'evidence' => [
                        'url' => 'http://<loopback>:8000/',
                        'http_code' => 200,
                    ],
                ],
            ],
        ],
        'compose unreadable' => [
            'healthy' => false,
            'ports' => [],
            'error' => 'could not read the application compose file: compose gone',
        ],
        'probe failed' => [
            'healthy' => false,
            'ports' => [],
            'error' => 'could not run the probe: OCI runtime exec failed',
        ],
        'probe failed ran' => [
            'count' => 1,
            'sha256' => '4bcfd6afdb5970de0d882a8177df1f1c8b44ba883d06ebfabe56db05590cf650',
        ],
        'restarting' => [
            'healthy' => false,
            'serving' => 'unknown',
            'ports' => [
                [
                    'port' => 8000,
                    'scheme' => null,
                    'status' => 'fail',
                    'http_code' => null,
                    'time' => null,
                    'detail' => 'connection refused',
                ],
                [
                    'port' => 8001,
                    'scheme' => null,
                    'status' => 'fail',
                    'http_code' => null,
                    'time' => null,
                    'detail' => 'the probe returned no result for this port',
                ],
            ],
            'domain' => [
                'verdict' => 'stubbed',
                'domain' => null,
                'http_code' => 2,
                'detail' => 'timeout 3',
            ],
            'checks' => [
                [
                    'id' => 'app-restart-looping',
                    'group' => 'runtime',
                    'status' => 'fail',
                    'severity' => 'error',
                    'title' => 'The application is restarting, not running.',
                    'detail' => 'Docker reports app (Restarting (1) 2 seconds ago). Nothing answered on any published port because the process keeps exiting.',
                    'fix' => 'Read the container output; the reason it exits is there.',
                    'evidence' => [
                        'restarting' => [
                            'app (Restarting (1) 2 seconds ago)',
                        ],
                    ],
                ],
            ],
        ],
        'restarting ran' => [
            'count' => 3,
            'sha256' => 'b62715cf9af7922d5ce951bd31af6fb166b27578c606714cb5331c5316409a52',
        ],
        'stopped app' => [
            'healthy' => false,
            'serving' => 'stopped',
            'ports' => [
                [
                    'port' => 8000,
                    'scheme' => null,
                    'status' => 'fail',
                    'http_code' => null,
                    'time' => null,
                    'detail' => 'connection refused',
                ],
            ],
            'domain' => [
                'verdict' => 'stubbed',
                'domain' => null,
                'http_code' => 1,
                'detail' => 'timeout 5',
            ],
            'checks' => [
                [
                    'id' => 'app-stopped',
                    'group' => 'runtime',
                    'status' => 'fail',
                    'severity' => 'error',
                    'title' => 'The application container is stopped.',
                    'detail' => 'Docker reports app (Exited (0) 3 hours ago). Nothing answered because it is not running.',
                    'fix' => 'Start it again, or redeploy.',
                    'evidence' => [
                        'stopped' => [
                            'app (Exited (0) 3 hours ago)',
                        ],
                    ],
                ],
            ],
        ],
        'stopped app ran' => [
            'count' => 3,
            'sha256' => '6db74f5a107a2fe4951f3af3022ab8e3f04afcfa66d123cb3099f151f411c256',
        ],
        'silent' => [
            'healthy' => false,
            'serving' => 'unknown',
            'ports' => [
                [
                    'port' => 8000,
                    'scheme' => null,
                    'status' => 'fail',
                    'http_code' => null,
                    'time' => null,
                    'detail' => 'connection refused',
                ],
            ],
            'domain' => [
                'verdict' => 'stubbed',
                'domain' => null,
                'http_code' => 1,
                'detail' => 'timeout 5',
            ],
            'checks' => [
                [
                    'id' => 'app-port-silent',
                    'group' => 'runtime',
                    'status' => 'fail',
                    'severity' => 'error',
                    'title' => 'The application is running but does not answer on the port it publishes.',
                    'detail' => 'app is running but listens on no TCP port yet: it is still starting, or waiting for something it needs.',
                    'fix' => 'Its output is in the deploy log and in the container output.',
                    'evidence' => [
                        'silent' => [
                            'app is running but listens on no TCP port yet: it is still starting, or waiting for something it needs.',
                        ],
                        'starting' => [
                            'app',
                        ],
                    ],
                ],
            ],
        ],
        'silent ran' => [
            'count' => 6,
            'sha256' => '18a7c239f1d80e76a22d51d9144ed899831cd749c805d45518acd0221a69ca05',
        ],
        'answering' => [
            'healthy' => true,
            'serving' => 'ok',
            'ports' => [
                [
                    'port' => 8000,
                    'scheme' => 'http',
                    'status' => 'ok',
                    'http_code' => 200,
                    'time' => 0.01,
                    'detail' => 'HTTP 200',
                ],
            ],
            'domain' => [
                'verdict' => 'stubbed',
                'domain' => null,
                'http_code' => 1,
                'detail' => 'timeout 5',
            ],
            'checks' => [
                [
                    'id' => 'no-directory-listing',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'error',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'no-server-error',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'error',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'no-service-unreachable',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'error',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'no-welcome-page',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'warning',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'not-a-dev-server',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'warning',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'not-a-stock-default-page',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'error',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'not-an-open-installer-at-root',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'warning',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'not-an-open-installer',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'warning',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
                [
                    'id' => 'not-placeholder',
                    'group' => '_baseline',
                    'status' => 'pass',
                    'severity' => 'error',
                    'title' => null,
                    'detail' => null,
                    'fix' => null,
                    'evidence' => null,
                ],
            ],
        ],
        'answering ran' => [
            'count' => 1,
            'sha256' => 'dedd3ab32b16133c1bb3012fe626cb5f87ee0edb0f61aeb0ed524e32ebe97360',
        ],
    ];

    /** @var list<string> */
    private array $ran = [];

    /** `docker compose ps --format json` of the running stack, with its publishers. */
    private string $published = '';

    /** @return array<string, array{0: list<int>, 1: int, 2: int, 3: int, 4: ?string, 5: bool, 6: list<int>}> */
    public static function probeScripts(): array
    {
        return [
            'defaults' => [[8080], 5, 3, 2, null, false, []],
            'domain, https port, deploy wait' => [[8080, 8443], 4, 2, 1, 'Shop.Example.com', true, [8443]],
            'nonsense tuning, blank domain' => [[0, 3000], 0, 0, -1, '  ', false, [3000]],
        ];
    }

    /**
     * @param  list<int>  $ports
     * @param  list<int>  $https
     */
    #[DataProvider('probeScripts')]
    public function test_the_probe_script_is_unchanged(array $ports, int $timeout, int $attempts, int $delay, ?string $domain, bool $wait, array $https): void
    {
        $script = AppHealth::probeScript($ports, $timeout, $attempts, $delay, $domain, $wait, $https);

        $this->pin('probe '.implode(',', $ports), ['bytes' => strlen($script), 'sha256' => hash('sha256', $script)]);
    }

    public function test_the_path_probe_script_is_unchanged(): void
    {
        $script = AppHealth::pathProbeScript('https', 8443, ['/login', '/a b'], 3, ' Shop.Example.com ');

        $this->pin('path probe', ['bytes' => strlen($script), 'sha256' => hash('sha256', $script)]);
    }

    public function test_probe_output_parses_as_before(): void
    {
        $raw = "8000\thttp\t200 0.0123456\t\t" . base64_encode('<p>hi</p>') . "\t/login\n"
            . "\n"
            . "0\thttp\t200 0.1\n"
            . "short\tline\n"
            . "8443\t\t000 0\tconnection refused\n"
            . "9000\thttps\t502 1.5\t\tnot-base64!\tnowhere\n";

        $this->pin('parsed', $this->normalised(AppHealth::parseProbeOutput($raw, [9000, 8000, 8443, 7000])));
    }

    public function test_restart_loop_checks_are_unchanged(): void
    {
        $before = [self::container('/project-app-1', 'app', 'running', 0, 0), self::container('/project-db-1', 'db', 'running', 0, 2)];
        $app = [
            self::container('/project-app-1', 'app', 'exited', 1, 1),
            self::container('/project-db-1', 'db', 'running', 0, 2),
            self::container('/project-worker-1', null, 'restarting', 3, 7),
        ];
        $sidecar = [self::container('/project-app-1', 'app', 'running', 0, 0), self::container('/project-db-1', 'db', 'restarting', 1, 3)];

        $this->pin('app loop', AppHealth::restartLoopBetween($before, $app, ['app']));
        $this->pin('unknown app loop', AppHealth::restartLoopBetween(null, $app));
        $this->pin('sidecar loop', AppHealth::restartLoopBetween($before, $sidecar, ['app']));
        $this->assertNull(AppHealth::restartLoopBetween($before, $before, ['app']));
    }

    public function test_the_stopped_check_is_unchanged(): void
    {
        $rows = self::COMPOSE_PS_STOPPED . "\n"
            . '{"Name":"project-ready-1","Service":"ready","State":"exited","Status":"Exited (0) 1 hour ago","ExitCode":0}' . "\n"
            . '{"Name":"project-cache-1","State":"paused","ExitCode":0}' . "\n"
            . '{"State":"created"}';

        $this->pin('stopped', AppHealth::stoppedFrom($rows, ['ready']));
    }

    public function test_the_blank_page_check_is_unchanged(): void
    {
        $front = new ProbedResponse(200, '  ', 'http://' . TestNetwork::LOOPBACK . ':8000/', 0.1);
        $warned = ['serving' => 'serving', 'checks' => [['id' => 'x', 'status' => 'fail', 'severity' => 'warning']]];
        $failed = ['serving' => 'error_page', 'checks' => [['id' => 'y', 'status' => 'fail', 'severity' => 'error']]];

        $this->pin('blank php', $this->normalised(AppHealth::withBlankPageCheck($warned, 'php', $front)));
        $this->pin('blank outranked', $this->normalised(AppHealth::withBlankPageCheck($failed, 'node', $front)));
        $this->assertSame($warned, AppHealth::withBlankPageCheck($warned, 'php', new ProbedResponse(204, '', 'u', 0.1)));
    }

    public function test_an_unreadable_compose_file_is_a_failed_check(): void
    {
        $health = $this->health(static fn (): array => throw new \RuntimeException("compose gone\nsecond line"), '');

        $this->pin('compose unreadable', $health->check());
        $this->assertSame([], $this->ran);
    }

    public function test_no_port_is_nothing_to_probe(): void
    {
        $this->assertSame(['healthy' => null, 'ports' => []], $this->health(static fn (): array => [], '')->check());
        $this->assertSame([], $this->ran);
    }

    public function test_a_probe_that_cannot_run_is_a_failed_check(): void
    {
        $health = $this->health(static fn (): array => [8000], new \RuntimeException("OCI runtime exec failed\nline two"));

        $this->pin('probe failed', $health->check(3, 2, 1));
        $this->pin('probe failed ran', $this->ranDigest());
    }

    public function test_a_cancelled_probe_is_rethrown(): void
    {
        $health = $this->health(static fn (): array => [8000], new DeployCancelledException('cancelled'));

        $this->expectException(DeployCancelledException::class);
        $health->check();
    }

    public function test_a_restarting_app_adds_the_loop_check(): void
    {
        $health = $this->health(static fn (): array => [8000, 8001], "8000\thttp\t000 0\tconnection refused\n", self::COMPOSE_PS_RESTARTING);

        $this->pin('restarting', $this->normalised($health->check(3, 2, 1, true)));
        $this->pin('restarting ran', $this->ranDigest());
    }

    public function test_a_stopped_app_is_served_as_stopped(): void
    {
        $health = $this->health(static fn (): array => [8000], "8000\thttp\t000 0\tconnection refused\n", self::COMPOSE_PS_STOPPED);

        $this->pin('stopped app', $this->normalised($health->check()));
        $this->pin('stopped app ran', $this->ranDigest());
    }

    public function test_a_running_app_that_answers_nothing_is_asked_what_it_listens_on(): void
    {
        $this->published = '{"Name":"project-app-1","Service":"app","State":"running","Publishers":[{"TargetPort":3000,"PublishedPort":8000,"Protocol":"tcp"}]}';
        $health = $this->health(static fn (): array => [8000], "8000\thttp\t000 0\tconnection refused\n", self::COMPOSE_PS_RUNNING);

        $this->pin('silent', $this->normalised($health->check()));
        $this->pin('silent ran', $this->ranDigest());
    }

    public function test_an_answering_app_is_checked(): void
    {
        $body = base64_encode('<html><head><title>Shop</title></head><body>Welcome</body></html>');
        $health = $this->health(static fn (): array => [8000], "8000\thttp\t200 0.01\t\t{$body}\t/\n", self::COMPOSE_PS_RUNNING, 'shop.example.com');

        $this->pin('answering', $this->normalised($health->check()));
        $this->pin('answering ran', $this->ranDigest());
    }

    /**
     * @param  callable(): list<int>  $ports
     */
    private function health(callable $ports, string|\Throwable $probe, string $composePs = '', ?string $domain = null): AppHealth
    {
        $user = $this->createStub(User::class);
        $user->method('getDetails')->willReturn(['app_port' => 8000]);
        $user->method('getAppPort')->willReturn(8000);
        if ($domain === null) {
            $user->method('getMainDomain')->willThrowException(new \RuntimeException('no database'));
        } else {
            $record = new Domain;
            $record->domain = $domain;
            $user->method('getMainDomain')->willReturn($record);
        }

        $dind = Mockery::mock(Dind::class);
        $dind->shouldReceive('userAppComposeFileToRun')->andReturn('/home/acme/docker-compose.yml');
        $dind->shouldReceive('userAppDirPath')->andReturn('/home/acme/project');
        $dind->shouldReceive('userAppComposeCommand')->andReturnUsing(
            static fn (array $args): array => ['docker', 'compose', '-f', '/home/acme/docker-compose.yml', ...$args]
        );
        $dind->shouldReceive('userModel')->andReturn($user);
        $dind->shouldReceive('composeFilePath')->andReturn('/opt/panelalpha/shared-hosting/users/acme/docker-compose.yml');
        $dind->shouldReceive('homeDirPath')->andReturn('/home/acme');
        $dind->shouldReceive('username')->andReturn('acme');

        $system = Mockery::mock(System::class);
        $system->shouldReceive('exec')->andReturnUsing(function (string|array $cmd) use ($probe, $composePs): string {
            $line = is_array($cmd) ? implode("\x1f", LocalRoot::plain($cmd)) : $cmd;
            $this->ran[] = $line;
            if (str_contains($line, 'for port in')) {
                if ($probe instanceof \Throwable) {
                    throw $probe;
                }

                return $probe;
            }
            if (is_array($cmd) && LocalRoot::action($cmd) === 'account-docker.listContainers') {
                return ComposePsFixture::listing((LocalRoot::values($cmd)[1] ?? '') === 'all' ? $composePs : $this->published);
            }
            if (is_array($cmd) && LocalRoot::action($cmd) === 'account-docker.containers') {
                return ComposePsFixture::inspect($composePs."\n".$this->published, array_slice(LocalRoot::values($cmd), 1));
            }

            return '';
        });
        $system->shouldReceive('filesystem')->andThrow(new \RuntimeException('no filesystem here'));
        $dind->shouldReceive('system')->andReturn($system);
        $dind->shouldReceive('shell')->andReturnUsing(fn (): ShellOperations => new ShellOperations($dind));
        $dind->shouldReceive('accountDaemon')->andReturnUsing(fn (): AccountDaemon => new AccountDaemon($dind));
        $dind->shouldReceive('userAppComposeProjectName')->andReturn('project');

        return new class ($dind, $ports) extends AppHealth {
            /** @var callable(): list<int> */
            private $ports;

            public function __construct(Dind $dind, callable $ports)
            {
                parent::__construct($dind);
                $this->ports = $ports;
            }

            public function ports(): array
            {
                return ($this->ports)();
            }

            public function reachability(array $ports, int $timeout = 5): array
            {
                return ['verdict' => 'stubbed', 'domain' => null, 'http_code' => count($ports), 'detail' => "timeout {$timeout}"];
            }
        };
    }

    /** @return array<string, mixed> the account daemon's inspect of a container */
    private static function container(string $name, ?string $service, string $status, int $exitCode, int $restarts): array
    {
        return [
            'Name' => $name,
            'Config' => ['Labels' => $service === null ? [] : ['com.docker.compose.service' => $service]],
            'State' => ['Status' => $status, 'ExitCode' => $exitCode],
            'RestartCount' => $restarts,
        ];
    }

    /** @return array{count: int, sha256: string} */
    private function ranDigest(): array
    {
        return ['count' => count($this->ran), 'sha256' => hash('sha256', implode("\n", $this->ran))];
    }

    private function normalised(mixed $value): mixed
    {
        return json_decode(str_replace(TestNetwork::LOOPBACK, '<loopback>', (string) json_encode($value)), true);
    }

    private function pin(string $name, mixed $actual): void
    {
        $this->assertArrayHasKey($name, self::PINS);
        $this->assertSame(self::PINS[$name], $actual, $name);
    }
}
