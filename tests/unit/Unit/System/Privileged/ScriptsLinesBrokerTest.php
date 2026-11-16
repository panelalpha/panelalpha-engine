<?php

namespace Tests\Unit\System\Privileged;

use App\Lib\Deploy\Dind\AccountImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\RootDockerLines;
use App\System\Privileged\RootFileLines;
use App\System\Privileged\RootScripts;
use App\System\Privileged\Scripts\ImagePipelines;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

final class ScriptsLinesBrokerTest extends TestCase
{
    private const CLIENT = '/usr/local/lib/panelalpha/pa-root';

    private const ACCOUNT_FILE = '/opt/panelalpha/shared-hosting/users/alice/docker-compose.yml';

    private const IMAGE = 'panelalpha/php:8.3-cli-bookworm-pa1234abcd';

    public function test_the_cases_the_broker_is_checked_against_are_the_gateways_own(): void
    {
        $file = (string) file_get_contents(dirname(__DIR__, 5).ScriptsLinesBrokerCases::FILE);

        $cases = json_decode($file, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(array_map(static fn (array $case): array => array_diff_key($case, ['sudo' => true]), $cases), ScriptsLinesBrokerCases::all());
    }

    public function test_every_case_asks_the_broker_and_none_runs_sudo(): void
    {
        foreach (ScriptsLinesBrokerCases::all() as $case) {
            $broker = is_array($case['broker']) ? $case['broker'] : explode(' ', $case['broker']);
            $this->assertSame(self::CLIENT, $broker[0], $case['name']);
            $this->assertNotContains('sudo', $broker, $case['name']);
        }
    }

    public function test_a_root_script_asks_for_its_action_with_its_values(): void
    {
        $scripts = Privileged::scripts();

        $this->assertSame(
            [self::CLIENT, 'scripts.makeHomeEntry', '--', '/home/alice/public_html', '0755', '1004:1004'],
            $scripts->makeHomeEntry('/home/alice/public_html', '0755', '1004:1004')
        );
        $this->assertSame(
            [self::CLIENT, 'scripts.composeImageLines', '--', '/home', 'alice', 'compose.yaml', 'docker-compose.yml'],
            $scripts->composeImageLines('/home', 'alice', ['compose.yaml', 'docker-compose.yml'])
        );
        $this->assertSame(
            [self::CLIENT, 'scripts.publishBuildCache', '--', '/w/out', TestNetwork::LOOPBACK.':5000/buildcache/a/b:main'],
            $scripts->publishBuildCache('/w/out', TestNetwork::LOOPBACK.':5000/buildcache/a/b:main')
        );
    }

    public function test_a_root_line_asks_pa_root_and_quotes_each_value(): void
    {
        $this->assertSame(self::CLIENT.' lines.inspectImage -- '.self::IMAGE, Privileged::dockerLines()->inspectImage(self::IMAGE));
        $this->assertSame(
            self::CLIENT.' lines.imageLabel -- ghcr.io/a/b:1 panelalpha.dockerfile.sha256',
            Privileged::dockerLines()->imageLabel('ghcr.io/a/b:1', 'panelalpha.dockerfile.sha256')
        );
        $this->assertSame(self::CLIENT." lines.buildFromStdin -- a:1 ''", Privileged::dockerLines()->buildFromStdin('a:1', false));
        $this->assertSame(
            self::CLIENT." lines.emptyDirectory -- '/opt/my engine/users/a/apache-sites'",
            Privileged::fileLines()->emptyDirectory('/opt/my engine/users/a/apache-sites')
        );
    }

    public function test_an_image_pipeline_runs_without_root_and_each_of_its_root_lines_asks_the_broker(): void
    {
        $scripts = Privileged::scripts();
        $pipelines = new ImagePipelines;

        foreach ([
            [$scripts->buildImageOnHost(self::IMAGE, "FROM 'php'", 'ghcr.io/'.self::IMAGE, true), $pipelines->hostBuild(self::IMAGE, "FROM 'php'", 'ghcr.io/'.self::IMAGE, false, true)],
            [$scripts->rebuildImageOnHost(self::IMAGE, 'FROM php', null), $pipelines->hostRebuild(self::IMAGE, 'FROM php', null)],
            [$scripts->loadImageFromHost('alice', self::IMAGE), $pipelines->loadFromHost('alice', self::IMAGE)],
        ] as [$argv, $script]) {
            $this->assertSame(['sh', '-c', $script], $argv);
            $this->assertStringNotContainsString('sudo', $script);
            $this->assertStringContainsString(self::CLIENT.' lines.', $script);
        }
        $this->assertStringContainsString(
            '| /usr/local/lib/panelalpha/pa-root lines.buildFromStdin -- '.self::IMAGE.' pull; }',
            $pipelines->hostBuild(self::IMAGE, 'FROM php', null, false, true)
        );
    }

    public function test_a_background_build_is_detached_by_the_caller_and_its_lines_go_to_the_broker(): void
    {
        $build = (new ImagePipelines)->hostBuild(self::IMAGE, 'FROM php', null, false, true);

        $this->assertSame(
            ['sh', '-c', 'nohup sh -c '.escapeshellarg($build).' >/dev/null 2>&1 &'],
            Privileged::scripts()->buildImageOnHostInBackground(self::IMAGE, 'FROM php', null)
        );
        $this->assertStringNotContainsString('sudo', $build);
    }

    public function test_the_image_stores_scripts_carry_no_sudo(): void
    {
        $store = new DindImageStore;
        $account = new EngineAccount('alice', '/home/alice', '1004:1004');

        foreach ([
            $store->seedCommand($account, 'node:22', false),
            $store->seedCommand($account, self::IMAGE, true),
            $store->seedCommand($account, 'ghcr.io/acme/app:1', false, true, '/home/alice/.docker'),
            $store->seedThroughHostCommand($account, 'node:latest'),
            $store->operatorSeedCommand($account, 'node:22'),
            $store->refreshCommand($account, 'node:22'),
            $store->parallelImportCommand($account, ['node:22', 'ghcr.io/acme/app:1'], 2),
            $store->hostBuildCommand(self::IMAGE, 'FROM php', true, false),
            $store->hostPublishCommand(self::IMAGE),
            AccountImage::ensureCommand('/opt/panelalpha/shared-hosting/templates/user/dind/project'),
            AccountImage::refreshCommand(),
        ] as $script) {
            $this->assertStringNotContainsString('sudo', $script);
        }
    }

    /** @return iterable<string, array{\Closure(RootScripts): mixed}> */
    public static function refusedScripts(): iterable
    {
        yield 'relative path to remove' => [fn (RootScripts $scripts) => $scripts->removeStartingWith('vhosts/a.conf')];
        yield 'path to remove climbing out' => [fn (RootScripts $scripts) => $scripts->removeStartingWith('/opt/vhosts/../../etc/a')];
        yield 'directory that is an option' => [fn (RootScripts $scripts) => $scripts->isRealDirectory('-L')];
        yield 'directory climbing out' => [fn (RootScripts $scripts) => $scripts->isRealDirectory('/home/alice/..')];
        yield 'home entry climbing out' => [fn (RootScripts $scripts) => $scripts->makeHomeEntry('/home/alice/../bob', '0755', '1004:1004')];
        yield 'home entry mode with a path' => [fn (RootScripts $scripts) => $scripts->makeHomeEntry('/home/alice/x', '0755 /etc', '1004:1004')];
        yield 'home entry that is the Docker storage' => [fn (RootScripts $scripts) => $scripts->makeHomeEntry('/home/alice/docker/', '0755', '1004:1004')];
        yield 'home entry owner that is an option' => [fn (RootScripts $scripts) => $scripts->makeHomeEntry('/home/alice/x', '0755', '--from=root')];
        yield 'relative homes dir' => [fn (RootScripts $scripts) => $scripts->composeImageLines('home', 'shopware', ['compose.yaml'])];
        yield 'excluded username climbing out' => [fn (RootScripts $scripts) => $scripts->composeImageLines('/home', '../root', ['compose.yaml'])];
        yield 'compose file name with a command' => [fn (RootScripts $scripts) => $scripts->composeImageLines('/home', 'shopware', ['x; id'])];
        yield 'compose file name with a slash' => [fn (RootScripts $scripts) => $scripts->composeImageLines('/home', 'shopware', ['../x.yml'])];
    }

    /** @param  \Closure(RootScripts): mixed  $build */
    #[DataProvider('refusedScripts')]
    public function test_a_script_value_the_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(Privileged::scripts());
    }

    /** @return iterable<string, array{callable(RootDockerLines): string}> */
    public static function refusedDockerLines(): iterable
    {
        yield 'inspect of an option' => [fn (RootDockerLines $d) => $d->inspectImage('--size')];
        yield 'label of an image with a quote' => [fn (RootDockerLines $d) => $d->imageLabel("a'b", 'x')];
        yield 'label with a quote' => [fn (RootDockerLines $d) => $d->imageLabel('nginx:1', 'x" }}{{ .Os')];
        yield 'pull of a second command' => [fn (RootDockerLines $d) => $d->pullQuietly('nginx; sudo id')];
        yield 'push of an option' => [fn (RootDockerLines $d) => $d->pushQuietly('--all-tags')];
        yield 'tag source with a space' => [fn (RootDockerLines $d) => $d->tag('a b', 'c:1')];
        yield 'tag target that is an option' => [fn (RootDockerLines $d) => $d->tag('a:1', '-f')];
        yield 'removal of uppercase' => [fn (RootDockerLines $d) => $d->removeImage('Nginx')];
        yield 'build tag that is an option' => [fn (RootDockerLines $d) => $d->buildFromStdin('--network=host', true)];
        yield 'build of a command' => [fn (RootDockerLines $d) => $d->buildFromStdin('x$(id)', false)];
        yield 'quiet build of a relative context' => [fn (RootDockerLines $d) => $d->buildQuietly('a:1', 'ctx')];
        yield 'quiet build of a context climbing out' => [fn (RootDockerLines $d) => $d->buildQuietly('a:1', '/opt/../etc')];
        yield 'running check of a second command' => [fn (RootDockerLines $d) => $d->isRunning('reg; sudo id')];
    }

    /** @param  callable(RootDockerLines): string  $build */
    #[DataProvider('refusedDockerLines')]
    public function test_a_docker_line_value_the_command_could_misread_is_refused_before_the_broker_is_asked(callable $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build(Privileged::dockerLines());
    }

    /** @return iterable<string, array{\Closure(RootFileLines): string}> */
    public static function refusedFileLines(): iterable
    {
        yield 'removeScripts, a relative directory' => [fn (RootFileLines $l) => $l->removeScripts('-rf /')];
        yield 'removeScriptsIfAny, a directory climbing out' => [fn (RootFileLines $l) => $l->removeScriptsIfAny('/home/u/../../etc')];
        yield 'removeProxyRuleConfigsIfAny, a relative directory' => [fn (RootFileLines $l) => $l->removeProxyRuleConfigsIfAny('vhosts')];
        yield 'emptyDirectory, a newline in the directory' => [fn (RootFileLines $l) => $l->emptyDirectory("/home/u\nid")];
        yield 'emptyDirectory, a relative directory' => [fn (RootFileLines $l) => $l->emptyDirectory('domains')];
    }

    /** @param  \Closure(RootFileLines): string  $line */
    #[DataProvider('refusedFileLines')]
    public function test_a_file_line_directory_the_command_could_misread_is_refused_before_the_broker_is_asked(\Closure $line): void
    {
        $this->expectException(InvalidArgument::class);

        $line(Privileged::fileLines());
    }

    public function test_a_compose_line_value_the_command_could_misread_is_refused_before_the_broker_is_asked(): void
    {
        $refused = 0;
        foreach ([
            fn () => Privileged::composeLines('users/a/docker-compose.yml')->pull(),
            fn () => Privileged::composeLines('/opt/../etc/docker-compose.yml')->build(),
        ] as $build) {
            try {
                $build();
            } catch (InvalidArgument) {
                $refused++;
            }
        }

        $this->assertSame(2, $refused);
    }
}
