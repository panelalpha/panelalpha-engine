<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceHardener;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins which bind sources, secret files and config files the hardener refuses, across
 * interpolation, the account user and the checkout path.
 */
class ServiceHardenerMountPolicyTest extends TestCase
{
    private const PROJECT = '/nonexistent-core-view/acct/project';

    /** @return list<mixed> */
    private static function volumes(): array
    {
        return [
            '/var/run/docker.sock:/var/run/docker.sock',
            '/var/run:/var/run',
            '/run:/host-run',
            './data:/data',
            'data:/data',
            '/var/lib/mysql',
            '../.panelalpha/cfg:/cfg',
            '../docker:/d',
            '../../etc:/etc2',
            '../:/account',
            '~/x:/x',
            '$HOME/x:/x',
            '${HOME}/x:/x',
            '/home/acct/.panelalpha/keep:/k',
            '/home/acct/.panelalpha/../docker:/k',
            '/home/acct/project/src:/src',
            '/home/acct/project:/app',
            '/home/acct/projectx:/app',
            '/:/host',
            '/tmp:/tmp',
            'C:\\data:/data',
            '\\\\server\\share:/s',
            '${DATA_DIR:-./data}:/data',
            '${X:-/var/run}:/x',
            '${UNSET}:/x',
            '${KNOWN}:/x',
            '${MAYBE}:/x',
            '/{/../etc:/x}',
            './.panelalpha/x:/x',
            '.:/app',
            ['type' => 'bind', 'source' => '/var/run', 'target' => '/x'],
            ['type' => 'bind', 'source' => 'data', 'target' => '/data'],
            ['type' => 'volume', 'source' => 'data', 'target' => '/data'],
            ['type' => 'bind', 'source' => '/etc', 'target' => '/etc'],
            ['source' => 'x', 'target' => '/var/run/docker.sock'],
            ['type' => 'bind', 'source' => '${X:-/var/run}', 'target' => '/x'],
            ['type' => 'bind', 'source' => '/home/acct/.panelalpha/k', 'target' => '/k'],
            42,
        ];
    }

    /** @return array<string, array{0: array<string, mixed>, 1: ?string, 2: ?string, 3: list<string>, 4: list<mixed>}> */
    public static function contexts(): array
    {
        $alwaysForbidden = [
            '/var/run/docker.sock:/var/run/docker.sock',
            '/var/run:/var/run',
            '/run:/host-run',
            '../docker:/d',
            '../../etc:/etc2',
            '../:/account',
            '~/x:/x',
            '$HOME/x:/x',
            '${HOME}/x:/x',
        ];
        $forbiddenLongForms = [
            '{"type":"bind","source":"/var/run","target":"/x"}',
            '{"type":"bind","source":"/etc","target":"/etc"}',
            '{"source":"x","target":"/var/run/docker.sock"}',
            '{"type":"bind","source":"${X:-/var/run}","target":"/x"}',
        ];
        $withoutAccount = [
            ...$alwaysForbidden,
            '/home/acct/.panelalpha/keep:/k',
            '/home/acct/.panelalpha/../docker:/k',
            '/home/acct/project/src:/src',
            '/home/acct/project:/app',
            '/home/acct/projectx:/app',
            '/:/host',
            '/tmp:/tmp',
            'C:\\data:/data',
            '\\\\server\\share:/s',
            '${X:-/var/run}:/x',
            '/{/../etc:/x}',
            ...$forbiddenLongForms,
            '{"type":"bind","source":"/home/acct/.panelalpha/k","target":"/k"}',
        ];
        $keptWithoutAccount = [
            './data:/data',
            'data:/data',
            '/var/lib/mysql',
            '../.panelalpha/cfg:/cfg',
            '${DATA_DIR:-./data}:/data',
            '${UNSET}:/x',
            '${KNOWN}:/x',
            '${MAYBE}:/x',
            './.panelalpha/x:/x',
            '.:/app',
            ['type' => 'bind', 'source' => 'data', 'target' => '/data'],
            ['type' => 'volume', 'source' => 'data', 'target' => '/data'],
            42,
        ];

        return [
            'account and checkout known' => [
                ['KNOWN' => '/etc', 'MAYBE' => ['./ok', '/etc'], 'DATA_DIR' => './data'],
                'acct',
                self::PROJECT,
                [
                    ...$alwaysForbidden,
                    '/home/acct/.panelalpha/../docker:/k',
                    '/home/acct/projectx:/app',
                    '/:/host',
                    '/tmp:/tmp',
                    'C:\\data:/data',
                    '\\\\server\\share:/s',
                    '${X:-/var/run}:/x',
                    '${KNOWN}:/x',
                    '${MAYBE}:/x',
                    '/{/../etc:/x}',
                    ...$forbiddenLongForms,
                ],
                [
                    './data:/data',
                    'data:/data',
                    '/var/lib/mysql',
                    '../.panelalpha/cfg:/cfg',
                    '/home/acct/.panelalpha/keep:/k',
                    '/home/acct/project/src:/src',
                    '/home/acct/project:/app',
                    '${DATA_DIR:-./data}:/data',
                    '${UNSET}:/x',
                    './.panelalpha/x:/x',
                    '.:/app',
                    ['type' => 'bind', 'source' => 'data', 'target' => '/data'],
                    ['type' => 'volume', 'source' => 'data', 'target' => '/data'],
                    ['type' => 'bind', 'source' => '/home/acct/.panelalpha/k', 'target' => '/k'],
                    42,
                ],
            ],
            'no account and no checkout' => [[], null, null, $withoutAccount, $keptWithoutAccount],
            'invalid account and relative checkout' => [
                ['KNOWN' => './ok'],
                'bad user!',
                'relative/project',
                $withoutAccount,
                $keptWithoutAccount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $env
     * @param  list<string>  $forbidden
     * @param  list<mixed>  $kept
     */
    #[DataProvider('contexts')]
    public function test_the_mounts_refused_and_kept(array $env, ?string $user, ?string $projectDir, array $forbidden, array $kept): void
    {
        $service = ['image' => 'x', 'volumes' => self::volumes()];

        $this->assertSame($forbidden, ServiceHardener::forbiddenMounts($service, $env, $user, $projectDir));
        $this->assertSame(['image' => 'x', 'volumes' => $kept], ServiceHardener::withoutEscapes($service, $env, $user, $projectDir));
    }

    public function test_a_service_left_with_no_mounts_loses_the_key(): void
    {
        $service = ServiceHardener::withoutEscapes(['image' => 'x', 'volumes' => ['/etc:/etc', '/:/r']], [], 'acct', null);

        $this->assertSame(['image' => 'x'], $service);
    }

    public function test_unsafe_secret_and_config_files_are_removed_with_their_references(): void
    {
        [$compose, $removed] = ServiceHardener::withoutUnsafeFileSources(self::fileSources(), [], 'acct', self::PROJECT);

        $this->assertSame([
            'secret db_pw: file /etc/shadow',
            'secret interp: file ${SECRET_FILE:-/var/run/docker.sock}',
            'config nginx: file ../../etc/nginx.conf',
            'config home: file ~/conf',
        ], $removed);
        $this->assertSame(<<<'YAML'
            secrets:
              ok_pw:
                file: ./secrets/pw
              env_pw:
                environment: PW
            configs: !override
              app:
                file: app.conf
            services:
              app:
                image: x
                secrets:
                  -
                    source: ok_pw
                configs: !reset
                  - app
              worker: !override
                image: 'y'
              web:
                image: z
              plain:
                image: p
              odd: notamap

            YAML, Yaml::dump($compose, 10, 2));
    }

    public function test_an_interpolated_secret_file_follows_the_environment(): void
    {
        [$compose, $removed] = ServiceHardener::withoutUnsafeFileSources(self::fileSources(), ['SECRET_FILE' => './s'], null, null);

        $this->assertSame([
            'secret db_pw: file /etc/shadow',
            'config nginx: file ../../etc/nginx.conf',
            'config home: file ~/conf',
        ], $removed);
        $this->assertSame(<<<'YAML'
            secrets:
              ok_pw:
                file: ./secrets/pw
              env_pw:
                environment: PW
              interp:
                file: '${SECRET_FILE:-/var/run/docker.sock}'
            configs: !override
              app:
                file: app.conf
            services:
              app:
                image: x
                secrets:
                  -
                    source: ok_pw
                  -
                    source: interp
                    target: /run/secrets/i
                configs: !reset
                  - app
              worker: !override
                image: 'y'
              web:
                image: z
              plain:
                image: p
              odd: notamap

            YAML, Yaml::dump($compose, 10, 2));
    }

    public function test_a_section_that_is_not_a_map_is_left_alone(): void
    {
        $compose = ['services' => ['a' => ['image' => 'x']], 'secrets' => 'notamap'];

        $this->assertSame([$compose, []], ServiceHardener::withoutUnsafeFileSources($compose, [], 'acct', null));
    }

    /** @return array<string, mixed> */
    private static function fileSources(): array
    {
        return Yaml::parse(<<<'YAML'
            secrets:
              db_pw:
                file: /etc/shadow
              ok_pw:
                file: ./secrets/pw
              env_pw:
                environment: PW
              interp:
                file: ${SECRET_FILE:-/var/run/docker.sock}
            configs: !override
              nginx:
                file: ../../etc/nginx.conf
              app:
                file: app.conf
              home:
                file: ~/conf
            services:
              app:
                image: x
                secrets:
                  - db_pw
                  - source: ok_pw
                  - source: interp
                    target: /run/secrets/i
                configs: !reset
                  - nginx
                  - app
              worker: !override
                image: y
                secrets:
                  - db_pw
              web:
                image: z
                configs:
                  - home
              plain:
                image: p
              odd: notamap
            YAML, Yaml::PARSE_CUSTOM_TAGS);
    }
}
