<?php

namespace Tests\Integration;

use App\Lib\Deploy\DeployLog\StepWatchdog;
use App\Lib\HttpAcmeChallengeStore;
use App\System;
use App\System\Filesystem;
use App\System\Privileged;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\LocalRoot;

/** The challenge store against an in-memory host: tokens, contents, and what it removes. */
class HttpAcmeChallengeStoreTest extends TestCase
{
    private const DIR = '/engine/data/webserver/acme-challenges/site.test';

    /** @param  array<string, string>  $files */
    private static function host(array $files = [], int $listExit = 0): System
    {
        $system = new class extends System
        {
            /** @var array<string, string> */
            public array $files = [];

            /** @var list<string> */
            public array $dirs = [];

            public int $listExit = 0;

            /** @var list<array<string>|string> */
            public array $commands = [];

            public function engineDirPath(): string
            {
                return '/engine';
            }

            public function directoryExists(string $path): bool
            {
                if (in_array($path, $this->dirs, true)) {
                    return true;
                }
                foreach (array_keys($this->files) as $file) {
                    if (str_starts_with($file, $path.'/')) {
                        return true;
                    }
                }

                return false;
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.listAllNames') {
                    if ($this->listExit !== 0) {
                        return FakeProcess::failed('ls failed', $this->listExit);
                    }
                    $dir = LocalRoot::values($cmd)[0];
                    $names = ['.', '..'];
                    foreach (array_keys($this->files) as $file) {
                        if (dirname($file) === $dir) {
                            $names[] = basename($file);
                        }
                    }

                    return FakeProcess::ok(implode("\n", $names)."\n");
                }

                return FakeProcess::ok();
            }

            public function runProcessWithCallbacks(
                string|array $cmd,
                array $env = [],
                int $timeout = 600,
                ?callable $onStart = null,
                ?callable $onOutput = null,
                ?StepWatchdog $watchdog = null
            ): Process {
                return $this->runProcess($cmd, $env, $timeout);
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;
                $action = is_array($cmd) ? LocalRoot::action($cmd) : null;
                foreach ($action === null ? [] : LocalRoot::values($cmd) as $path) {
                    foreach (array_keys($this->files) as $file) {
                        if ($file === $path || ($action === 'files.removeTree' && str_starts_with($file, $path.'/'))) {
                            unset($this->files[$file]);
                        }
                    }
                    $this->dirs = array_values(array_diff($this->dirs, [$path]));
                }

                return '';
            }

            public function filesystem(): Filesystem
            {
                $host = $this;

                return new class($host) extends Filesystem
                {
                    public function __construct(private System $host)
                    {
                        parent::__construct($host);
                    }

                    public function fileExists(string $path): bool
                    {
                        return isset($this->host->files[$path]);
                    }

                    public function fileGetContents(string $path): string
                    {
                        return $this->host->files[$path];
                    }

                    public function makeDirWithParents(string $target, ?string $chown = null): void
                    {
                        $this->host->dirs[] = $target;
                    }

                    public function filePutContents(string $path, string $contents, ?string $chown = null, ?string $chmod = null): void
                    {
                        $this->host->files[$path] = $contents;
                        $this->host->commands[] = "put {$path} {$chmod}";
                    }
                };
            }
        };
        $system->files = $files;
        $system->listExit = $listExit;

        return $system;
    }

    public function test_paths_are_below_the_engine_data_directory(): void
    {
        $store = new HttpAcmeChallengeStore(self::host());

        $this->assertSame('/engine/data/webserver/acme-challenges', $store->basePath());
        $this->assertSame(self::DIR, $store->domainDir('site.test'));
        $this->assertSame(self::DIR.'/tok-1_A', $store->challengePath('site.test', 'tok-1_A'));
    }

    public function test_a_domain_without_a_directory_has_no_tokens_and_is_disabled(): void
    {
        $store = new HttpAcmeChallengeStore(self::host());

        $this->assertSame([], $store->listTokens('site.test'));
        $this->assertSame([], $store->list('site.test'));
        $this->assertSame(
            ['http_acme_challenges_enabled' => false, 'http_acme_challenges_dir' => self::DIR],
            $store->templateVars('site.test')
        );
    }

    public function test_tokens_are_sorted_and_names_outside_the_alphabet_ignored(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([
            self::DIR.'/zeta' => 'zeta.key',
            self::DIR.'/alpha' => 'alpha.key',
            self::DIR.'/not a token' => 'x',
        ]));

        $this->assertSame(['alpha', 'zeta'], $store->listTokens('site.test'));
        $this->assertTrue($store->enabled('site.test'));
        $this->assertSame([
            ['token' => 'alpha', 'content' => 'alpha.key'],
            ['token' => 'zeta', 'content' => 'zeta.key'],
        ], $store->list('site.test'));
    }

    public function test_a_failed_listing_reads_as_no_tokens(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([self::DIR.'/alpha' => 'a'], 2));

        $this->assertSame([], $store->listTokens('site.test'));
        $this->assertFalse($store->enabled('site.test'));
    }

    public function test_get_and_exists_read_one_token(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([self::DIR.'/alpha' => 'alpha.key']));

        $this->assertSame('alpha.key', $store->get('site.test', 'alpha'));
        $this->assertNull($store->get('site.test', 'beta'));
        $this->assertTrue($store->exists('site.test', 'alpha'));
        $this->assertFalse($store->exists('site.test', 'beta'));
    }

    public function test_the_first_put_enables_the_domain_and_writes_a_world_readable_file(): void
    {
        $system = self::host();
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_enabled' => true], $store->put('site.test', 'alpha', 'alpha.key'));
        $this->assertSame([self::DIR], $system->dirs);
        $this->assertContains('put '.self::DIR.'/alpha 644', $system->commands);
        $this->assertSame('alpha.key', $store->get('site.test', 'alpha'));

        $this->assertSame(['became_enabled' => false], $store->put('site.test', 'beta', 'beta.key'));
    }

    public function test_put_refuses_a_token_that_already_exists(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([self::DIR.'/alpha' => 'alpha.key']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Challenge token already exists');

        $store->put('site.test', 'alpha', 'other.key');
    }

    public function test_deleting_a_missing_token_changes_nothing(): void
    {
        $system = self::host();

        $this->assertSame(
            ['became_disabled' => false, 'deleted' => false],
            (new HttpAcmeChallengeStore($system))->delete('site.test', 'alpha')
        );
        $this->assertSame([], $system->commands);
    }

    public function test_deleting_the_last_token_removes_the_directory_and_disables_the_domain(): void
    {
        $system = self::host([self::DIR.'/alpha' => 'alpha.key']);
        $system->dirs = [self::DIR];
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_disabled' => true, 'deleted' => true], $store->delete('site.test', 'alpha'));
        $this->assertContains(Privileged::files()->removeIfExists(self::DIR.'/alpha'), $system->commands);
        $this->assertContains(Privileged::files()->removeTree(self::DIR), $system->commands);
        $this->assertSame([], $system->dirs);
    }

    public function test_deleting_one_of_two_tokens_keeps_the_domain_enabled(): void
    {
        $system = self::host([self::DIR.'/alpha' => 'a', self::DIR.'/beta' => 'b']);
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_disabled' => false, 'deleted' => true], $store->delete('site.test', 'alpha'));
        $this->assertNotContains(Privileged::files()->removeTree(self::DIR), $system->commands);
        $this->assertSame(['beta'], $store->listTokens('site.test'));
    }

    public function test_delete_all_removes_the_tree_and_counts_the_tokens(): void
    {
        $system = self::host([self::DIR.'/alpha' => 'a', self::DIR.'/beta' => 'b']);
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_disabled' => true, 'deleted_count' => 2], $store->deleteAll('site.test'));
        $this->assertSame([], $store->listTokens('site.test'));
        $this->assertSame(['became_disabled' => false, 'deleted_count' => 0], $store->deleteAll('site.test'));
    }

    public function test_delete_domain_dir_runs_only_when_the_directory_exists(): void
    {
        $system = self::host();
        $store = new HttpAcmeChallengeStore($system);
        $store->deleteDomainDir('site.test');
        $this->assertSame([], $system->commands);

        $system->dirs = [self::DIR];
        $store->deleteDomainDir('site.test');
        $this->assertSame([Privileged::files()->removeTree(self::DIR)], $system->commands);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['a.b'];
        yield 'slash' => ['../etc'];
        yield 'space' => ['a b'];
    }

    #[DataProvider('invalidTokens')]
    public function test_a_token_outside_the_base64url_alphabet_is_refused(string $token): void
    {
        $store = new HttpAcmeChallengeStore(self::host());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ACME challenge token');

        $store->challengePath('site.test', $token);
    }

    public function test_empty_content_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Challenge content must not be empty');

        (new HttpAcmeChallengeStore(self::host()))->put('site.test', 'alpha', '');
    }

    public function test_content_over_the_maximum_length_is_refused(): void
    {
        $store = new HttpAcmeChallengeStore(self::host());
        $store->assertValidContent(str_repeat('a', HttpAcmeChallengeStore::MAX_CONTENT_LENGTH));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Challenge content exceeds maximum length');

        $store->assertValidContent(str_repeat('a', HttpAcmeChallengeStore::MAX_CONTENT_LENGTH + 1));
    }
}
