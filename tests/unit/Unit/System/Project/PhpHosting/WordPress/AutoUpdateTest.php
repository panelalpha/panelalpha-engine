<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\Models\Domain;
use App\Models\User;
use App\System\Project;
use App\System\Project\FileManager;
use App\System\Project\PhpHosting\WordPress\AutoUpdate;
use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\WpCli;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Contract for the auto-update resource: one read and one write with core,
 * plugins, and themes fields, and the WP-CLI argv including resolved --path.
 */
class AutoUpdateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_maps_core_plugins_and_themes(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => "minor\n", 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[{"name":"akismet","status":"enabled"},{"name":"hello","status":"enabled"}]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[{"name":"twentytwentyfour","status":"disabled"}]', 'stderr' => ''],
            ],
        );

        $body = $auto->get();

        $this->assertSame(
            ['config', 'get', 'WP_AUTO_UPDATE_CORE', '--type=constant', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['plugin', 'auto-updates', 'status', '--all', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
        $this->assertSame(
            ['theme', 'auto-updates', 'status', '--all', '--format=json', '--path=/home/alice/example.test/public_html'],
            $sequence[2]
        );
        $this->assertSame(
            [
                'core' => 'minor',
                'plugins' => 'on',
                'themes' => 'off',
                'plugin_items' => ['akismet' => 'enabled', 'hello' => 'enabled'],
                'theme_items' => ['twentytwentyfour' => 'disabled'],
            ],
            $body
        );
    }

    public function test_get_reports_a_partly_enabled_type_item_by_item(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'true', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[{"name":"akismet","status":"enabled"},{"name":"hello","status":"disabled"}]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
            ],
        );

        $body = $auto->get();

        $this->assertSame('off', $body['plugins']);
        $this->assertSame(['akismet' => 'enabled', 'hello' => 'disabled'], $body['plugin_items']);
    }

    public function test_get_missing_core_constant_defaults_to_minor(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: not defined'],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
            ],
        );

        $body = $auto->get();

        $this->assertSame('minor', $body['core']);
        $this->assertSame('on', $body['plugins']);
        $this->assertSame('on', $body['themes']);
    }

    public function test_get_maps_core_true_false(): void
    {
        foreach ([['true', 'on'], ['false', 'off'], ['1', 'on'], ['0', 'off']] as [$raw, $expected]) {
            $sequence = [];
            $auto = $this->autoUpdateWithFakeRunner(
                $sequence,
                results: [
                    ['exit_code' => 0, 'stdout' => $raw, 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ],
            );
            $this->assertSame($expected, $auto->get()['core'], $raw);
        }
    }

    public function test_set_writes_core_plugins_and_themes(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'Success: Updated the constant.', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => 'Success: Enabled plugin auto-updates.', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => 'Success: Disabled theme auto-updates.', 'stderr' => ''],
            ],
        );

        $body = $auto->set(['core' => 'on', 'plugins' => 'on', 'themes' => 'off']);

        $this->assertSame(
            ['config', 'set', 'WP_AUTO_UPDATE_CORE', 'true', '--type=constant', '--raw', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
        $this->assertSame(
            ['plugin', 'auto-updates', 'enable', '--all', '--path=/home/alice/example.test/public_html'],
            $sequence[1]
        );
        $this->assertSame(
            ['theme', 'auto-updates', 'disable', '--all', '--path=/home/alice/example.test/public_html'],
            $sequence[2]
        );
        $this->assertSame(['core' => 'on', 'plugins' => 'on', 'themes' => 'off'], $body);
    }

    public function test_set_core_off_and_minor(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner($sequence, path: '/home/alice/other.test/public_html');

        $auto->set(['core' => 'off', 'plugins' => 'off', 'themes' => 'on']);

        $this->assertSame('false', $sequence[0][3]);
        $this->assertSame('--path=/home/alice/other.test/public_html', $sequence[0][6]);
        $this->assertSame(
            ['plugin', 'auto-updates', 'disable', '--all', '--path=/home/alice/other.test/public_html'],
            $sequence[1]
        );
        $this->assertSame(
            ['theme', 'auto-updates', 'enable', '--all', '--path=/home/alice/other.test/public_html'],
            $sequence[2]
        );

        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner($sequence);
        $auto->set(['core' => 'minor', 'plugins' => 'off', 'themes' => 'off']);
        // A string, not --raw: raw `minor` is an undefined constant that breaks the site.
        $this->assertSame(
            ['config', 'set', 'WP_AUTO_UPDATE_CORE', 'minor', '--type=constant', '--path=/home/alice/example.test/public_html'],
            $sequence[0]
        );
    }

    public function test_set_writes_only_the_fields_given(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner($sequence);

        $body = $auto->set(['plugins' => 'on']);

        $this->assertSame(
            [['plugin', 'auto-updates', 'enable', '--all', '--path=/home/alice/example.test/public_html']],
            $sequence
        );
        $this->assertSame(['plugins' => 'on'], $body);
    }

    public function test_set_succeeds_when_some_items_already_had_the_state(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                [
                    'exit_code' => 1,
                    'stdout' => '',
                    'stderr' => "Warning: Auto-updates already enabled for plugin hello-dolly.\nError: Only enabled 3 of 4 plugin auto-updates.",
                ],
                ['exit_code' => 0, 'stdout' => '[{"name":"akismet","status":"enabled"},{"name":"hello-dolly","status":"enabled"}]', 'stderr' => ''],
            ],
        );

        $this->assertSame(['plugins' => 'on'], $auto->set(['plugins' => 'on']));
        $this->assertSame(['plugin', 'auto-updates', 'status', '--all', '--format=json', '--path=/home/alice/example.test/public_html'], $sequence[1]);
    }

    public function test_set_fails_when_an_item_did_not_get_the_state(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: Only disabled 1 of 2 theme auto-updates.'],
                ['exit_code' => 0, 'stdout' => '[{"name":"a","status":"disabled"},{"name":"b","status":"enabled"}]', 'stderr' => ''],
            ],
        );

        try {
            $auto->set(['themes' => 'off']);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: Only disabled 1 of 2 theme auto-updates.', $e->getMessage());
        }
    }

    public function test_set_nonzero_exit_throws(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [['exit_code' => 1, 'stdout' => 'Error: could not set constant', 'stderr' => '']],
        );

        try {
            $auto->set(['core' => 'on', 'plugins' => 'on', 'themes' => 'on']);
            $this->fail('Expected WordPressException');
        } catch (WordPressException $e) {
            $this->assertSame('Error: could not set constant', $e->getMessage());
            $this->assertSame(422, $e->httpStatus);
        }
    }

    /**
     * @param  list<list<string>>  $sequence
     * @param  list<array{exit_code: int, stdout: string, stderr: string}>  $results
     */
    private function autoUpdateWithFakeRunner(
        array &$sequence,
        array $results = [['exit_code' => 0, 'stdout' => '', 'stderr' => '']],
        ?string $path = null,
    ): AutoUpdate {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $call = 0;
        $project->shouldReceive('runWpCli')
            ->andReturnUsing(function (array $args) use (&$sequence, &$call, $results): array {
                $sequence[] = $args;
                $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
                $call++;

                return $result;
            });
        $user->shouldReceive('project')->andReturn($project);
        $user->shouldReceive('getMainDomain')->andReturn($this->domain('/example.test/public_html'));

        return new AutoUpdate(WpCli::for($user, $path));
    }

    private function domain(string $documentRoot): Domain
    {
        $domain = Mockery::mock(Domain::class);
        $domain->shouldReceive('getDocumentRoot')->andReturn($documentRoot);

        return $domain;
    }
}
