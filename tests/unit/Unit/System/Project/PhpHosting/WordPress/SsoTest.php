<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\WpCli;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\ContainerMustUsePlugin;
use App\System\Project\PhpHosting\WordPress\EnsuresMustUsePlugin;
use App\System\Project\PhpHosting\WordPress\MustUsePlugin;
use App\System\Project\PhpHosting\WordPress\Sso;
use Illuminate\Container\Container;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for SSO: MU plugin ensured before the URL is returned, resource
 * body shape, resolved --path on WP-CLI, and non-zero exit as Exception.
 */
class SsoTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_ensures_mu_plugin_before_returning_url_and_passes_default_path(): void
    {
        $sequence = [];
        $sso = $this->ssoWithFakes(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'Success: Updated custom field.', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => "https://example.test\n", 'stderr' => ''],
            ],
            installRoot: '/home/alice/example.test/public_html',
        );

        $body = $sso->create('admin');

        $this->assertSame('ensure', $sequence[0][0]);
        $this->assertSame('/home/alice/example.test/public_html', $sequence[0][1]);
        $this->assertSame('wpcli', $sequence[1][0]);
        $this->assertSame(
            [
                'user', 'meta', 'update', 'admin', 'panelalpha_sso',
                // sha1 value is dynamic; check prefix of argv instead below
            ],
            array_slice($sequence[1][1], 0, 5)
        );
        $this->assertSame('panelalpha_sso', $sequence[1][1][4]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $sequence[1][1][5]);
        $this->assertSame('--path=/home/alice/example.test/public_html', $sequence[1][1][6]);

        $this->assertSame('wpcli', $sequence[2][0]);
        $this->assertSame(
            ['option', 'get', 'siteurl', '--path=/home/alice/example.test/public_html'],
            $sequence[2][1]
        );

        $this->assertArrayHasKey('url', $body);
        $this->assertMatchesRegularExpression(
            '#^https://example\.test/\?panelalpha_sso=\d+_[A-Za-z0-9]{64}$#',
            $body['url']
        );
        $this->assertStringNotContainsString('controlpanel_sso', $body['url']);

        // Meta value is sha1 of the token returned in the URL.
        $token = substr($body['url'], strlen('https://example.test/?panelalpha_sso='));
        $this->assertSame(sha1($token), $sequence[1][1][5]);
    }

    public function test_create_with_explicit_path(): void
    {
        $sequence = [];
        $sso = $this->ssoWithFakes(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'ok', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => 'https://other.test', 'stderr' => ''],
            ],
            installRoot: '/home/alice/other.test/public_html',
            path: '/home/alice/other.test/public_html',
        );

        $body = $sso->create('editor');

        $this->assertSame('/home/alice/other.test/public_html', $sequence[0][1]);
        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[1][1][6]);
        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[2][1][3]);
        $this->assertStringStartsWith('https://other.test/?panelalpha_sso=', $body['url']);
    }

    public function test_non_zero_exit_throws_with_command_output(): void
    {
        $sequence = [];
        $sso = $this->ssoWithFakes(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => "Error: Invalid user ID, email or login: 'missing'",
                    'stderr' => '',
                ],
            ],
        );

        try {
            $sso->create('missing');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame("Error: Invalid user ID, email or login: 'missing'", $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('ensure', $sequence[0][0]);
        }
    }

    public function test_a_container_install_gets_the_plugin_written_through_wp_cli(): void
    {
        $calls = [];
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $user->setDetails(['template' => 'dind']);
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$calls): array {
                $calls[] = $args;

                return ['exit_code' => 0, 'stdout' => $args[0] === 'option' ? 'https://wp.test' : '', 'stderr' => ''];
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldNotReceive('getMainDomain');

        $previous = Container::getInstance();
        Container::setInstance(new class extends Container {
            public function resourcePath(string $path = ''): string
            {
                return dirname(__DIR__, 7).'/core/resources/'.$path;
            }
        });
        try {
            $body = (new Sso(WpCli::for($user), $user))->create('admin');
            $source = (string) file_get_contents((new MustUsePlugin($user))->sourcePath());
        } finally {
            Container::setInstance($previous);
        }

        $this->assertSame('eval', $calls[0][0]);
        $this->assertCount(2, $calls[0], 'no host --path for a container install');
        $this->assertNotSame('', $source);
        $this->assertStringContainsString(var_export(base64_encode($source), true), $calls[0][1]);
        $this->assertSame(['option', 'get', 'siteurl'], $calls[2]);
        $this->assertStringStartsWith('https://wp.test/?panelalpha_sso=', $body['url']);
    }

    public function test_the_container_plugin_code_writes_the_file_only_when_it_differs(): void
    {
        $dir = sys_get_temp_dir().'/pa-mu-'.bin2hex(random_bytes(4));
        $code = ContainerMustUsePlugin::writeCode("<?php // plugin\n");
        $run = static function () use ($dir, $code): void {
            if (!defined('WPMU_PLUGIN_DIR')) {
                define('WPMU_PLUGIN_DIR', $dir);
            }
            eval($code);
        };

        try {
            mkdir($dir);
            $run();
            $this->assertSame("<?php // plugin\n", file_get_contents($dir.'/panelalpha-app.php'));
            touch($dir.'/panelalpha-app.php', 1000);
            clearstatcache();
            $run();
            clearstatcache();
            $this->assertSame(1000, filemtime($dir.'/panelalpha-app.php'), 'same contents: not rewritten');
        } finally {
            @unlink($dir.'/panelalpha-app.php');
            @rmdir($dir);
        }
    }

    /**
     * @param list<array{0: string, 1: mixed}> $sequence
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function ssoWithFakes(
        array &$sequence,
        array $results = [],
        string $installRoot = '/home/alice/example.test/public_html',
        ?string $path = null,
    ): Sso {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = ['wpcli', $args];
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        $mu = Mockery::mock(EnsuresMustUsePlugin::class);
        $mu->shouldReceive('ensure')
            ->once()
            ->with($installRoot)
            ->andReturnUsing(function (string $root) use (&$sequence): void {
                $sequence[] = ['ensure', $root];
            });

        return new Sso(WpCli::for($user, $path), $user, $mu);
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
