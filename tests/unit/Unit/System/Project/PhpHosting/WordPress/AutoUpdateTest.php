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
                ['exit_code' => 0, 'stdout' => "\"minor\"\n", 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[{"name":"akismet","status":"enabled"},{"name":"hello","status":"enabled"}]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[{"name":"twentytwentyfour","status":"disabled"}]', 'stderr' => ''],
            ],
        );

        $body = $auto->get();

        $this->assertSame(
            ['config', 'get', 'WP_AUTO_UPDATE_CORE', '--type=constant', '--format=json', '--path=/home/alice/example.test/public_html'],
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
            ['config', 'get', 'DISALLOW_FILE_MODS', '--type=constant', '--path=/home/alice/example.test/public_html'],
            $sequence[3]
        );
        $this->assertSame(
            ['config', 'get', 'AUTOMATIC_UPDATER_DISABLED', '--type=constant', '--path=/home/alice/example.test/public_html'],
            $sequence[4]
        );
        $this->assertSame(
            [
                'core' => 'minor',
                'plugins' => 'on',
                'themes' => 'off',
                'plugin_items' => ['akismet' => 'enabled', 'hello' => 'enabled'],
                'theme_items' => ['twentytwentyfour' => 'disabled'],
                'blocked_by' => [],
            ],
            $body
        );
    }

    public function test_get_unknown_core_value_reads_off_and_is_reported(): void
    {
        foreach (['bogus', "'bogus'"] as $raw) {
            $sequence = [];
            $auto = $this->autoUpdateWithFakeRunner(
                $sequence,
                results: [
                    ['exit_code' => 0, 'stdout' => $raw, 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[{"name":"akismet","status":"enabled"}]', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[{"name":"twentytwentyfour","status":"disabled"}]', 'stderr' => ''],
                ],
            );

            $body = $auto->get();

            $this->assertSame('off', $body['core'], $raw);
            $this->assertSame(['WP_AUTO_UPDATE_CORE'], $body['blocked_by'], $raw);
            $this->assertSame(['akismet' => 'enabled'], $body['plugin_items'], $raw);
            $this->assertSame(['twentytwentyfour' => 'disabled'], $body['theme_items'], $raw);
        }
    }

    public function test_get_reports_disallow_file_mods_and_automatic_updater_disabled(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'minor', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => 'true', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '1', 'stderr' => ''],
            ],
        );

        $this->assertSame(['DISALLOW_FILE_MODS', 'AUTOMATIC_UPDATER_DISABLED'], $auto->get()['blocked_by']);
    }

    public function test_get_blocked_by_keeps_a_fixed_order(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'bogus', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => 'true', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => 'true', 'stderr' => ''],
            ],
        );

        $this->assertSame(
            ['WP_AUTO_UPDATE_CORE', 'DISALLOW_FILE_MODS', 'AUTOMATIC_UPDATER_DISABLED'],
            $auto->get()['blocked_by'],
        );
    }

    public function test_get_blocking_constant_counts_only_true_values(): void
    {
        $blocked = ['true', 'TRUE', '1', 'on', '"true"', "'on'"];
        $notBlocked = [
            ['exit_code' => 0, 'stdout' => 'false'],
            ['exit_code' => 0, 'stdout' => '0'],
            ['exit_code' => 0, 'stdout' => 'off'],
            ['exit_code' => 0, 'stdout' => ''],
            ['exit_code' => 0, 'stdout' => 'yes'],
            ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Error: Constant DISALLOW_FILE_MODS not defined.'],
            ['stdout' => 'true'],  // no exit_code: treated as a failure
        ];

        foreach ($blocked as $raw) {
            $sequence = [];
            $auto = $this->autoUpdateWithFakeRunner(
                $sequence,
                results: [
                    ['exit_code' => 0, 'stdout' => 'minor', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => $raw, 'stderr' => ''],
                ],
            );
            $this->assertSame(['DISALLOW_FILE_MODS'], $auto->get()['blocked_by'], $raw);
        }

        foreach ($notBlocked as $result) {
            $sequence = [];
            $auto = $this->autoUpdateWithFakeRunner(
                $sequence,
                results: [
                    ['exit_code' => 0, 'stdout' => 'minor', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                    ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                    ['stderr' => '', ...$result],
                ],
            );
            $this->assertSame([], $auto->get()['blocked_by'], $result['stdout']);
        }
    }

    public function test_get_valid_core_false_is_not_blocked(): void
    {
        $sequence = [];
        $auto = $this->autoUpdateWithFakeRunner(
            $sequence,
            results: [
                ['exit_code' => 0, 'stdout' => 'false', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
                ['exit_code' => 0, 'stdout' => '[]', 'stderr' => ''],
            ],
        );

        $body = $auto->get();

        $this->assertSame('off', $body['core']);
        $this->assertSame([], $body['blocked_by']);
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
     * Each mode reads back as written: WP-CLI echoes a false constant as an empty line, so the read asks for JSON.
     */
    public function test_each_core_mode_written_reads_back_as_itself(): void
    {
        foreach (['off', 'on', 'minor'] as $mode) {
            $auto = $this->autoUpdateOn($this->wordPressConfig());

            $auto->set(['core' => $mode]);

            $this->assertSame($mode, $auto->get()['core'], $mode);
        }
    }

    /**
     * `wp config set` and `wp config get` on one constant, printed the way WP_CLI::print_value() prints a scalar.
     *
     * @return \Closure(list<string>): array{exit_code: int, stdout: string, stderr: string}
     */
    private function wordPressConfig(): \Closure
    {
        $constant = null;

        return function (array $args) use (&$constant): array {
            if (array_slice($args, 0, 2) === ['config', 'set']) {
                $constant = in_array('--raw', $args, true) ? $args[3] === 'true' : $args[3];
            }
            $printed = in_array('--format=json', $args, true) ? json_encode($constant) : (string) $constant;
            $stdout = array_slice($args, 0, 2) === ['config', 'get'] ? $printed."\n" : '[]';

            return ['exit_code' => 0, 'stdout' => $stdout, 'stderr' => ''];
        };
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
        $call = 0;

        return $this->autoUpdateOn(function (array $args) use (&$sequence, &$call, $results): array {
            $sequence[] = $args;
            $result = $results[$call] ?? ['exit_code' => 0, 'stdout' => '', 'stderr' => ''];
            $call++;

            return $result;
        }, $path);
    }

    /**
     * @param  \Closure(list<string>): array{exit_code: int, stdout: string, stderr: string}  $wpCli
     */
    private function autoUpdateOn(\Closure $wpCli, ?string $path = null): AutoUpdate
    {
        $user = Mockery::mock(User::class)->makePartial();
        $user->username = 'alice';
        $project = Mockery::mock(Project::class);
        $project->shouldReceive('homeDirPath')->andReturn('/home/alice');
        $project->shouldReceive('resolvePath')
            ->andReturnUsing(fn (string $path): string => (new FileManager($project))->resolvePath($path));
        $project->shouldReceive('runWpCli')->andReturnUsing($wpCli);
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
