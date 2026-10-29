<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\Plugins;
use App\System\Project\PhpHosting\WordPress\Themes;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One plugin or theme: get answers its details, a 404 when it is not
 * installed, and the WP-CLI output for any other failure; is-installed reads
 * exit 1 as "not installed" and any other non-zero exit as a failure.
 */
class PluginThemeGetTest extends TestCase
{
    private const PATH = '--path=/home/alice/example.test/public_html';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function kinds(): array
    {
        return ['plugin' => ['plugin'], 'theme' => ['theme']];
    }

    #[DataProvider('kinds')]
    public function test_get_returns_the_decoded_details_with_fields(string $kind): void
    {
        $calls = [];
        $module = $this->module($kind, $calls, [
            ['exit_code' => 0, 'stdout' => '{"name":"akismet","status":"active"}', 'stderr' => ''],
        ]);

        $data = $module->get('akismet', 'name,status');

        $this->assertSame([[$kind, 'get', 'akismet', '--format=json', '--fields=name,status', self::PATH]], $calls);
        $this->assertSame(['name' => 'akismet', 'status' => 'active'], $data);
    }

    #[DataProvider('kinds')]
    public function test_get_of_a_missing_one_is_a_404(string $kind): void
    {
        $calls = [];
        $module = $this->module($kind, $calls, [
            ['exit_code' => 1, 'stdout' => '', 'stderr' => "Error: The 'gone' {$kind} could not be found."],
            ['exit_code' => 1, 'stdout' => '', 'stderr' => ''],
        ]);

        try {
            $module->get('gone');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame(ucfirst($kind) . " 'gone' is not installed.", $e->getMessage());
        }
        $this->assertSame([$kind, 'is-installed', 'gone', self::PATH], $calls[1]);
    }

    #[DataProvider('kinds')]
    public function test_get_that_fails_for_an_installed_one_keeps_the_wp_cli_output(string $kind): void
    {
        $calls = [];
        $module = $this->module($kind, $calls, [
            ['exit_code' => 255, 'stdout' => '', 'stderr' => 'PHP Fatal error: boom'],
            ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
        ]);

        try {
            $module->get('akismet');
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('PHP Fatal error: boom', $e->getMessage());
        }
    }

    #[DataProvider('kinds')]
    public function test_is_installed_reads_exit_zero_and_one(string $kind): void
    {
        $calls = [];
        $module = $this->module($kind, $calls, [
            ['exit_code' => 0, 'stdout' => '', 'stderr' => ''],
            ['exit_code' => 1, 'stdout' => '', 'stderr' => ''],
        ]);

        $this->assertSame([$kind => 'akismet', 'installed' => true], $module->isInstalled('akismet'));
        $this->assertSame([$kind => 'gone', 'installed' => false], $module->isInstalled('gone'));
        $this->assertSame([$kind, 'is-installed', 'akismet', self::PATH], $calls[0]);
    }

    #[DataProvider('kinds')]
    public function test_is_installed_on_a_site_that_fails_to_load_is_an_error_not_false(string $kind): void
    {
        $calls = [];
        $module = $this->module($kind, $calls, [
            ['exit_code' => 255, 'stdout' => '', 'stderr' => 'PHP Fatal error: boom'],
        ]);

        $this->expectException(WordPressException::class);
        $this->expectExceptionMessage('PHP Fatal error: boom');

        $module->isInstalled('akismet');
    }

    /**
     * @param list<list<string>> $calls
     * @param list<array{exit_code: int, stdout: string, stderr: string}> $results
     */
    private function module(string $kind, array &$calls, array $results): Plugins|Themes
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$calls, $results): array {
                $result = $results[count($calls)] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $calls[] = $args;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn('/example.test/public_html');
        $user->shouldReceive('getMainDomain')->andReturn($domain);

        $cli = WpCli::for($user);

        return $kind === 'plugin' ? new Plugins($cli) : new Themes($cli);
    }
}
