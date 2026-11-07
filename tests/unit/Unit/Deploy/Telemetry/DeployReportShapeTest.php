<?php

namespace Tests\Unit\Deploy\Telemetry;

use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\Framework;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whole reports, compared with assertSame so the key order is pinned too: the receiving
 * end reads the payload as it is sent, and every optional block appears in a fixed place.
 */
class DeployReportShapeTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     */
    #[DataProvider('reports')]
    public function test_the_whole_report_is_pinned(string $case, array $input, array $expected): void
    {
        $this->assertSame($expected, DeployReport::build($input), $case);
    }

    public static function reports(): array
    {
        $inputs = self::inputs();
        $expected = self::expected();

        $cases = [];
        foreach ($inputs as $name => $input) {
            $cases[$name] = [$name, $input, $expected[$name]];
        }

        return $cases;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function inputs(): array
    {
        return [
            'everything a public deploy can carry' => [
                'id' => '01JGXR0000000000000000000A',
                'occurred_at' => 1756137600,
                'outcome' => DeployReport::OUTCOME_FAILED,
                'tier' => DeployReport::TIER_LOG,
                'install_id' => 'install-abc',
                'username' => 'acme7x',
                'latest' => [
                    'id' => '20260825-131200-a1b2c3',
                    'stage' => 'running',
                    'stages' => [
                        ['name' => 'preparing', 'started_at' => 100, 'finished_at' => 141],
                        ['name' => 'cloning', 'started_at' => 141, 'finished_at' => 147],
                        'not a stage',
                        ['name' => 'running', 'started_at' => 147, 'finished_at' => 140],
                        ['name' => 'running', 'started_at' => '147', 'finished_at' => 334.5],
                    ],
                ],
                'details' => [
                    'deploy_source' => 'git',
                    'deploy_strategy' => 'nextjs',
                    'deploy_platform' => 'nextjs',
                    'deploy_label' => 'Next.js',
                    'deploy_runtime' => 'node',
                    'deploy_port' => '3000',
                    'git_branch' => 'main',
                    'git_commit' => 'abcdef1234567890',
                    'cpu_limit' => '1.5',
                    'memory_limit' => 4096,
                    'health_checked' => true,
                    'health_healthy' => false,
                    AppHealth::DETAIL_REACHABLE => 'other_site',
                    'health_ports' => [
                        ['port' => 3000, 'status' => 'open', 'http_code' => '200'],
                        ['port' => 9000, 'status' => 5],
                        'junk',
                        ['status' => 'closed'],
                    ],
                    'health_serving' => 'placeholder',
                    'health_failed_checks' => [
                        ['id' => 'blank_page', 'severity' => 'error', 'message' => 'your /srv/app/index.html is blank'],
                        ['id' => 7],
                        'junk',
                        ['id' => 'slow_start'],
                    ],
                    'domain' => [
                        'source' => 'panelalpha_online',
                        'tunnel' => 'wdns',
                        'tls_terminated_at' => 'proxy',
                        'publicly_resolvable' => true,
                        'fallback_reason' => '',
                    ],
                    'ssl' => ['status' => 'valid', 'issuer' => ''],
                ],
                'error' => "#5 12.3 the frobnicator declined to frob\nwhile writing /home/acme7x/project/x",
                'signal' => null,
                'repo_url' => 'https://github.com/Vercel/next.js.git',
                'repo_private' => false,
                'repo_source' => 'account',
                'checkout' => [
                    'present' => true,
                    'shallow' => true,
                    'submodules' => 1,
                    'lfs' => false,
                    'detached' => true,
                    'branch' => 'main',
                    'committed_at' => 1756137600 - 3 * 86400 - 5,
                    'remote' => 'https://github.com/vercel/next.js.git',
                    'commit' => 'ffffffffffffffffffff',
                ],
                'manifests' => ['package.json', 'Bun.lock', 'src', 'package.json', ' Dockerfile ', 7],
                'candidates' => ['nextjs', 'node', 'NODE', 'node', 'static', 'bad id', 3],
                'packages' => [
                    new AppPackage(
                        ecosystem: 'node',
                        file: 'package.json',
                        name: 'next',
                        nameSource: 'package.json name',
                        frameworks: [new Framework('nextjs', 'Next.js', '^15.0', '15.1.0', 'package-lock.json')],
                        dependencyCounts: ['dependencies' => 3],
                    ),
                    'not a package',
                ],
                'domains' => [
                    ['domain' => 'next.example.test', 'type' => 'main', 'tunnel' => 'wdns', 'primary' => true],
                    ['domain' => 'www.example.test', 'type' => 'alias', 'alias' => true, 'primary' => true],
                    ['domain' => str_repeat('a', 254)],
                    ['domain' => ''],
                    'junk',
                    ['domain' => 'sub.example.test', 'type' => str_repeat('t', 65), 'tunnel' => 5],
                ],
                'log_tail' => ['#12 building', 'ENOSPC: no space left on device', 'acme7x was here'],
                'source_bundle' => ['bytes' => 1024, 'files' => 3],
            ],
            'a private repository with an address nothing parses' => [
                'id' => 'b',
                'occurred_at' => 1756137600,
                'outcome' => DeployReport::OUTCOME_PARTIAL,
                'tier' => DeployReport::TIER_REPO,
                'install_id' => 'install-abc',
                'username' => 'acme7x',
                'latest' => ['stage' => 'cloning', 'stages' => 'junk'],
                'details' => ['deploy_strategy' => 'php', 'git_commit' => 'abcdef1234567890', 'git_branch' => 'secret-branch'],
                'error' => 'fatal: secret-branch is gone',
                'signal' => 'clone_failed',
                'repo_url' => 'not a url',
                'repo_private' => true,
                'checkout' => ['present' => true, 'branch' => 'secret-branch', 'committed_at' => 0, 'commit' => 'abc'],
                'domains' => 'not a list',
                'source_bundle' => 'not an array',
            ],
            'a private repository that parses' => [
                'id' => 'c',
                'occurred_at' => 1756137600,
                'outcome' => DeployReport::OUTCOME_FAILED,
                'tier' => 9,
                'install_id' => 'install-abc',
                'username' => 'acme7x',
                'latest' => ['stage' => 'running'],
                'details' => ['health_checked' => false, 'health_healthy' => 'yes'],
                'error' => null,
                'repo_url' => 'git@gitlab.example.test:Team/App.GIT',
                'repo_private' => true,
                'repo_source' => 'checkout',
                'domains' => [['domain' => 'only.example.test']],
                'log_tail' => [],
            ],
            'a recovered deploy at the metadata tier' => [
                'id' => 'd',
                'occurred_at' => 1756137600,
                'outcome' => DeployReport::OUTCOME_RECOVERED,
                'tier' => -3,
                'install_id' => 'install-abc',
                'username' => 'acme7x',
                'latest' => ['precheck_rejected' => true, 'stage' => 'cloning'],
                'details' => [],
                'error' => str_repeat('x', 700),
                'repo_url' => 'https://github.com/o/r.git',
            ],
            'no remote, a checkout' => [
                'id' => 'e',
                'occurred_at' => 1756137600,
                'outcome' => DeployReport::OUTCOME_SUCCESS,
                'tier' => DeployReport::TIER_REPO,
                'install_id' => 'install-abc',
                'username' => 'acme7x',
                'latest' => [],
                'details' => ['domain' => ['fallback_reason' => 'acme7x lost panelalpha_online: HTTP 403', 'publicly_resolvable' => null]],
                'error' => '',
                'repo_url' => '  ',
                'checkout' => ['present' => true],
                'domains' => [],
            ],
            'no remote, no checkout' => [
                'id' => 'f',
                'occurred_at' => 1756137600,
                'outcome' => DeployReport::OUTCOME_FAILED,
                'tier' => DeployReport::TIER_REPO,
                'install_id' => 'install-abc',
                'username' => 'acme7x',
                'latest' => [],
                'details' => [],
                'error' => 'Error: something',
                'checkout' => 'junk',
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function expected(): array
    {
        return [
            'everything a public deploy can carry' => [
                'id' => '01JGXR0000000000000000000A',
                'schema' => 1,
                'tier' => 2,
                'occurred_at' => 1756137600,
                'outcome' => 'failed',
                'account' => '9e9cc4f24799e97b',
                'fingerprint' => '42aadc2fa919d977a27babfbd614274f',
                'deploy' => [
                    'id' => '20260825-131200-a1b2c3',
                    'source' => 'git',
                    'strategy' => 'nextjs',
                    'platform' => 'nextjs',
                    'label' => 'Next.js',
                    'runtime' => 'node',
                    'port_hint' => 3000,
                    'manifests' => [
                        'bun.lock',
                        'dockerfile',
                        'package.json',
                    ],
                    'candidates' => [
                        'nextjs',
                        'node',
                        'static',
                    ],
                    'git_commit' => 'abcdef123456',
                ],
                'failure' => [
                    'stage' => 'running',
                    'rule' => null,
                    'explained' => false,
                    'message' => null,
                    'signature' => "#5 12.3 the frobnicator declined to frob\nwhile writing /home/<account>/project/x",
                ],
                'timings' => [
                    'preparing' => 41,
                    'cloning' => 6,
                    'running' => 187,
                    'total' => 234,
                ],
                'limits' => [
                    'cpu' => 1.5,
                    'memory_mb' => 4096,
                ],
                'health' => [
                    'checked' => true,
                    'healthy' => false,
                    'reachable' => 'other_site',
                    'ports' => [
                        [
                            'port' => 3000,
                            'status' => 'open',
                            'http_code' => 200,
                        ],
                        [
                            'port' => null,
                            'status' => 'closed',
                            'http_code' => null,
                        ],
                    ],
                    'serving' => 'placeholder',
                    'failed' => [
                        [
                            'id' => 'blank_page',
                            'severity' => 'error',
                        ],
                        [
                            'id' => 'slow_start',
                            'severity' => null,
                        ],
                    ],
                ],
                'domain' => [
                    'source' => 'panelalpha_online',
                    'tunnel' => 'wdns',
                    'tls_terminated_at' => 'proxy',
                    'ssl_status' => 'valid',
                    'publicly_resolvable' => true,
                    'primary' => 'next.example.test',
                    'names' => [
                        [
                            'domain' => 'next.example.test',
                            'type' => 'main',
                            'tunnel' => 'wdns',
                        ],
                        [
                            'domain' => 'www.example.test',
                            'type' => 'alias',
                            'alias' => true,
                        ],
                        [
                            'domain' => 'sub.example.test',
                        ],
                    ],
                ],
                'app' => [
                    'ecosystem' => 'node',
                    'file' => 'package.json',
                    'named' => true,
                    'frameworks' => [
                        [
                            'id' => 'nextjs',
                            'name' => 'Next.js',
                            'constraint' => '^15.0',
                            'version' => '15.1.0',
                            'major' => 15,
                        ],
                    ],
                    'dependencies' => [
                        'dependencies' => 3,
                    ],
                    'name' => 'next',
                ],
                'repo' => [
                    'present' => true,
                    'parsed' => true,
                    'private' => false,
                    'host' => 'github.com',
                    'path' => 'Vercel/next.js',
                    'source' => 'account',
                    'checked_out' => true,
                    'shallow' => true,
                    'submodules' => true,
                    'lfs' => false,
                    'detached' => true,
                    'branch' => 'main',
                    'age_days' => 3,
                ],
                'log_tail' => [
                    '#12 building',
                    'ENOSPC: no space left on device',
                    '<account> was here',
                ],
                'source_bundle' => [
                    'bytes' => 1024,
                    'files' => 3,
                ],
            ],
            'a private repository with an address nothing parses' => [
                'id' => 'b',
                'schema' => 1,
                'tier' => 1,
                'occurred_at' => 1756137600,
                'outcome' => 'partial',
                'account' => '9e9cc4f24799e97b',
                'fingerprint' => '812af91fa7a06fce2ffa9b527a317d1a',
                'deploy' => [
                    'id' => null,
                    'source' => null,
                    'strategy' => 'php',
                    'platform' => null,
                    'label' => null,
                    'runtime' => null,
                    'port_hint' => null,
                    'manifests' => [],
                    'candidates' => [],
                    'git_commit' => null,
                ],
                'failure' => [
                    'stage' => 'cloning',
                    'rule' => 'clone_failed',
                    'explained' => true,
                    'message' => 'fatal: secret-branch is gone',
                    'signature' => 'clone_failed',
                ],
                'timings' => [],
                'limits' => [
                    'cpu' => null,
                    'memory_mb' => null,
                ],
                'repo' => [
                    'present' => true,
                    'parsed' => false,
                    'private' => true,
                    'checked_out' => true,
                    'shallow' => false,
                    'submodules' => false,
                    'lfs' => false,
                ],
            ],
            'a private repository that parses' => [
                'id' => 'c',
                'schema' => 1,
                'tier' => 2,
                'occurred_at' => 1756137600,
                'outcome' => 'failed',
                'account' => '9e9cc4f24799e97b',
                'fingerprint' => 'f83d88adbe1d94d5edfc3a004db1ed0f',
                'deploy' => [
                    'id' => null,
                    'source' => null,
                    'strategy' => null,
                    'platform' => null,
                    'label' => null,
                    'runtime' => null,
                    'port_hint' => null,
                    'manifests' => [],
                    'candidates' => [],
                    'git_commit' => null,
                ],
                'failure' => [
                    'stage' => 'running',
                    'rule' => null,
                    'explained' => false,
                    'message' => null,
                    'signature' => '',
                ],
                'timings' => [],
                'limits' => [
                    'cpu' => null,
                    'memory_mb' => null,
                ],
                'health' => [
                    'checked' => false,
                    'healthy' => null,
                ],
                'domain' => [
                    'names' => [
                        [
                            'domain' => 'only.example.test',
                        ],
                    ],
                ],
                'repo' => [
                    'present' => true,
                    'parsed' => true,
                    'private' => true,
                    'host' => 'gitlab.example.test',
                    'path_hash' => '876239dbfcd56b3a',
                    'source' => 'checkout',
                ],
                'log_tail' => [],
            ],
            'a recovered deploy at the metadata tier' => [
                'id' => 'd',
                'schema' => 1,
                'tier' => 0,
                'occurred_at' => 1756137600,
                'outcome' => 'recovered',
                'account' => '9e9cc4f24799e97b',
                'fingerprint' => '8bfd5f9b47f677c7f8fb2b7519d6178b',
                'deploy' => [
                    'id' => null,
                    'source' => null,
                    'strategy' => null,
                    'platform' => null,
                    'label' => null,
                    'runtime' => null,
                    'port_hint' => null,
                    'manifests' => [],
                    'candidates' => [],
                ],
                'failure' => [
                    'stage' => 'precheck',
                    'rule' => null,
                    'explained' => true,
                    'message' => '<b64>',
                    'signature' => 'recovered',
                ],
                'timings' => [],
                'limits' => [
                    'cpu' => null,
                    'memory_mb' => null,
                ],
            ],
            'no remote, a checkout' => [
                'id' => 'e',
                'schema' => 1,
                'tier' => 1,
                'occurred_at' => 1756137600,
                'outcome' => 'success',
                'account' => '9e9cc4f24799e97b',
                'fingerprint' => 'f83d88adbe1d94d5edfc3a004db1ed0f',
                'deploy' => [
                    'id' => null,
                    'source' => null,
                    'strategy' => null,
                    'platform' => null,
                    'label' => null,
                    'runtime' => null,
                    'port_hint' => null,
                    'manifests' => [],
                    'candidates' => [],
                    'git_commit' => null,
                ],
                'failure' => [
                    'stage' => null,
                    'rule' => null,
                    'explained' => false,
                    'message' => null,
                    'signature' => '',
                ],
                'timings' => [],
                'limits' => [
                    'cpu' => null,
                    'memory_mb' => null,
                ],
                'domain' => [
                    'fallback_reason' => '<account> lost panelalpha_online: HTTP 403',
                ],
                'repo' => [
                    'present' => true,
                    'parsed' => false,
                    'private' => false,
                    'checked_out' => true,
                    'shallow' => false,
                    'submodules' => false,
                    'lfs' => false,
                ],
            ],
            'no remote, no checkout' => [
                'id' => 'f',
                'schema' => 1,
                'tier' => 1,
                'occurred_at' => 1756137600,
                'outcome' => 'failed',
                'account' => '9e9cc4f24799e97b',
                'fingerprint' => 'b90daaf7ec9301c099dca94d32a19569',
                'deploy' => [
                    'id' => null,
                    'source' => null,
                    'strategy' => null,
                    'platform' => null,
                    'label' => null,
                    'runtime' => null,
                    'port_hint' => null,
                    'manifests' => [],
                    'candidates' => [],
                    'git_commit' => null,
                ],
                'failure' => [
                    'stage' => null,
                    'rule' => null,
                    'explained' => false,
                    'message' => null,
                    'signature' => 'Error: something',
                ],
                'timings' => [],
                'limits' => [
                    'cpu' => null,
                    'memory_mb' => null,
                ],
                'repo' => [
                    'present' => false,
                ],
            ],
        ];
    }
}
