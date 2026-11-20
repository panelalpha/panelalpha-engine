<?php

namespace Tests\Integration\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\PushRefused;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\TestableProjectGit;

/**
 * A push, and a push_first pull, that git refuses leave the checkout as the user had it; the origin is a real bare
 * repository refusing through a real pre-receive hook.
 */
class ProjectGitFailedPushTest extends TestCase
{
    private string $root;

    private string $origin;

    private string $checkout;

    private string $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/pa-git-failed-push-'.bin2hex(random_bytes(8));
        $this->origin = $this->root.'/origin.git';
        $this->checkout = $this->root.'/checkout';
        $this->author = $this->root.'/author';
        mkdir($this->root, 0700, true);

        $this->git($this->root, ['init', '--bare', '-b', 'main', $this->origin]);
        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', $this->origin, $this->author]);
        $this->configure($this->author);
        $this->git($this->author, ['checkout', '-B', 'main']);
        $this->commit($this->author, ['index.php' => "v1\n", 'config.php' => "config\n", 'old.php' => "old\n"], 'Initial commit');
        $this->git($this->author, ['push', 'origin', 'main']);

        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', '-b', 'main', $this->origin, $this->checkout]);
        $this->configure($this->checkout);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_a_refused_push_keeps_every_uncommitted_change(): void
    {
        $this->changeTheCheckout();
        $before = $this->snapshot();
        $this->refuseEveryPush();

        $e = $this->failing(fn () => $this->makeGit()->push());

        $this->assertSame(422, $e->httpStatus);
        $this->assertSame(PushRefused::REJECTED, $e->problemCode);
        $this->assertStringContainsString('pre-receive hook declined', $e->getMessage());
        $this->assertSame($before, $this->snapshot());
        $this->assertChangesKept();
        $this->assertTrue($this->makeGit()->status()['dirty']);
    }

    public function test_a_refused_push_of_local_commits_keeps_the_empty_directories(): void
    {
        $this->commit($this->checkout, ['local.php' => "local\n"], 'Local commit');
        mkdir($this->checkout.'/cache');
        $before = $this->snapshot();
        $this->refuseEveryPush();

        $this->failing(fn () => $this->makeGit()->push());

        $this->assertSame($before, $this->snapshot());
        $this->assertDirectoryExists($this->checkout.'/cache');
    }

    public function test_a_push_that_goes_through_commits_every_change_and_pushes_it(): void
    {
        $this->changeTheCheckout();

        $status = $this->makeGit()->push();

        $this->assertFalse($status['dirty']);
        $this->assertSame($this->head($this->checkout), trim($this->git($this->origin, ['rev-parse', 'main'])));
        $this->assertSame("new\n", $this->git($this->origin, ['show', 'main:uploads/new.txt']));
        $this->assertChangesKept();
    }

    public function test_a_push_first_pull_whose_push_is_refused_keeps_the_uncommitted_changes(): void
    {
        $this->pushFromAuthor(['other.php' => "theirs\n"], 'Remote commit');
        $this->changeTheCheckout();
        $before = $this->snapshot();
        $this->refuseEveryPush();

        $this->failing(fn () => $this->makeGit()->pull('push_first'));

        $this->assertSame($before, $this->snapshot());
        $this->assertChangesKept();
        $this->assertFileDoesNotExist($this->checkout.'/other.php');
    }

    public function test_a_push_first_pull_with_a_merge_conflict_keeps_the_uncommitted_changes(): void
    {
        $this->pushFromAuthor(['index.php' => "theirs\n"], 'Conflicting commit');
        $this->changeTheCheckout();
        $before = $this->snapshot();

        $e = $this->failing(fn () => $this->makeGit()->pull('push_first'));

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('index.php', $e->getMessage());
        $this->assertSame($before, $this->snapshot());
        $this->assertChangesKept();
    }

    public function test_a_push_first_pull_of_a_clean_checkout_whose_push_is_refused_puts_head_back(): void
    {
        $this->commit($this->checkout, ['local.php' => "local\n"], 'Local commit');
        $this->pushFromAuthor(['other.php' => "theirs\n"], 'Remote commit');
        mkdir($this->checkout.'/cache');
        $before = $this->snapshot();
        $this->refuseEveryPush();

        $this->failing(fn () => $this->makeGit()->pull('push_first'));

        $this->assertSame($before, $this->snapshot());
        $this->assertFileDoesNotExist($this->checkout.'/other.php');
        $this->assertDirectoryExists($this->checkout.'/cache');
    }

    public function test_a_push_into_an_empty_repository_creates_the_branch(): void
    {
        $empty = $this->root.'/empty.git';
        $this->git($this->root, ['init', '--bare', '-b', 'main', $empty]);
        $fresh = $this->root.'/fresh-empty';
        mkdir($fresh);
        $this->git($fresh, ['init', '-b', 'main']);
        $this->configure($fresh);
        $this->git($fresh, ['remote', 'add', 'origin', $empty]);
        file_put_contents($fresh.'/index.php', "mine\n");

        $status = $this->makeGit(checkout: $fresh, origin: $empty)->push();

        $this->assertFalse($status['dirty']);
        $this->assertSame($this->head($fresh), trim($this->git($empty, ['rev-parse', 'main'])));
        $this->assertSame("mine\n", $this->git($empty, ['show', 'main:index.php']));
    }

    public function test_a_refused_push_into_an_empty_repository_leaves_no_commit_and_a_retry_pushes(): void
    {
        $empty = $this->root.'/empty-refusing.git';
        $this->git($this->root, ['init', '--bare', '-b', 'main', $empty]);
        $hook = $empty.'/hooks/pre-receive';
        file_put_contents($hook, "#!/bin/sh\necho 'pushes are declined here' >&2\nexit 1\n");
        chmod($hook, 0755);
        $fresh = $this->root.'/fresh-refused';
        mkdir($fresh);
        $this->git($fresh, ['init', '-b', 'main']);
        $this->configure($fresh);
        $this->git($fresh, ['remote', 'add', 'origin', $empty]);
        file_put_contents($fresh.'/index.php', "mine\n");
        file_put_contents($fresh.'/staged.php', "staged\n");
        $this->git($fresh, ['add', 'staged.php']);

        $this->failing(fn () => $this->makeGit(checkout: $fresh, origin: $empty)->push());

        $this->assertSame('', trim($this->git($fresh, ['rev-list', '--all'])));
        $this->assertSame("A  staged.php\n?? index.php\n", $this->git($fresh, ['status', '--porcelain']));

        unlink($hook);
        $status = $this->makeGit(checkout: $fresh, origin: $empty)->push();

        $this->assertFalse($status['dirty']);
        $this->assertSame("mine\n", $this->git($empty, ['show', 'main:index.php']));
        $this->assertSame("staged\n", $this->git($empty, ['show', 'main:staged.php']));
    }

    public function test_a_push_first_pull_whose_push_is_refused_keeps_a_write_made_during_the_merge(): void
    {
        $this->pushFromAuthor(['other.php' => "theirs\n"], 'Remote commit');
        $this->changeTheCheckout();
        $this->refuseEveryPush();
        $writeAfterMerge = function (string $command): void {
            if (str_contains($command, ' merge origin/')) {
                file_put_contents($this->checkout.'/window.txt', "written during the merge\n");
            }
        };

        $this->failing(fn () => $this->makeGit(after: $writeAfterMerge)->pull('push_first'));

        $this->assertSame('written during the merge', $this->readTrimmed($this->checkout.'/window.txt'));
        $this->assertChangesKept();
        $this->assertFileDoesNotExist($this->checkout.'/other.php');
    }

    public function test_a_refused_push_keeps_staged_content_that_differs_from_the_working_copy(): void
    {
        file_put_contents($this->checkout.'/index.php', "staged\n");
        $this->git($this->checkout, ['add', 'index.php']);
        file_put_contents($this->checkout.'/index.php', "working\n");
        $this->refuseEveryPush();

        $this->failing(fn () => $this->makeGit()->push());

        $this->assertSame("staged\n", $this->git($this->checkout, ['show', ':index.php']));
        $this->assertSame('working', $this->readTrimmed($this->checkout.'/index.php'));
    }

    public function test_a_refused_first_commit_keeps_the_files(): void
    {
        $fresh = $this->root.'/fresh';
        mkdir($fresh);
        $this->git($fresh, ['init', '-b', 'main']);
        $this->configure($fresh);
        $this->git($fresh, ['remote', 'add', 'origin', $this->origin]);
        file_put_contents($fresh.'/index.php', "mine\n");
        mkdir($fresh.'/cache');

        $e = $this->failing(fn () => $this->makeGit(checkout: $fresh)->push());

        $this->assertStringContainsString('[rejected]', $e->getMessage());
        $this->assertSame('mine', $this->readTrimmed($fresh.'/index.php'));
        $this->assertDirectoryExists($fresh.'/cache');
    }

    public function test_a_commit_reported_as_failed_after_it_was_written_keeps_every_change(): void
    {
        $this->changeTheCheckout();
        $before = $this->snapshot();

        $this->failing(fn () => $this->makeGit(after: $this->failingAfter(' commit '))->push());

        $this->assertSame($before, $this->snapshot());
        $this->assertChangesKept();
    }

    public function test_a_push_first_pull_whose_commit_is_reported_as_failed_keeps_every_change(): void
    {
        $this->pushFromAuthor(['other.php' => "theirs\n"], 'Remote commit');
        $this->changeTheCheckout();
        $before = $this->snapshot();

        $this->failing(fn () => $this->makeGit(after: $this->failingAfter(' commit '))->pull('push_first'));

        $this->assertSame($before, $this->snapshot());
        $this->assertChangesKept();
    }

    public function test_a_push_leaves_the_lockfile_an_install_generated_out_of_the_commit(): void
    {
        $this->commit($this->checkout, ['package.json' => "{\"name\":\"shop\"}\n"], 'Add package.json');
        $this->git($this->checkout, ['push', 'origin', 'main']);
        file_put_contents($this->checkout.'/package-lock.json', "{\"lockfileVersion\": 3}\n");
        file_put_contents($this->checkout.'/index.php', "local edit\n");

        $this->makeGit()->push();

        $this->assertSame("local edit\n", $this->git($this->origin, ['show', 'main:index.php']));
        $this->assertSame('', $this->git($this->origin, ['ls-tree', '--name-only', 'main', 'package-lock.json']));
        $this->assertFileExists($this->checkout.'/package-lock.json');
    }

    public function test_a_push_whose_only_change_is_the_lockfile_an_install_generated_pushes_nothing(): void
    {
        $this->commit($this->checkout, ['package.json' => "{\"name\":\"shop\"}\n"], 'Add package.json');
        $this->git($this->checkout, ['push', 'origin', 'main']);
        $before = trim($this->git($this->origin, ['rev-parse', 'main']));
        file_put_contents($this->checkout.'/package-lock.json', "{\"lockfileVersion\": 3}\n");

        $this->makeGit()->push();

        $this->assertSame($before, trim($this->git($this->origin, ['rev-parse', 'main'])));
        $this->assertFileExists($this->checkout.'/package-lock.json');
    }

    public function test_head_goes_back_to_the_commit_read_before_the_push_whoever_moves_the_backup_ref(): void
    {
        $this->changeTheCheckout();
        $before = $this->snapshot();
        $this->refuseEveryPush();
        $moveTheRef = function (string $command): void {
            if (str_contains($command, ' commit ')) {
                $this->git($this->checkout, ['update-ref', 'refs/panelalpha/backup', 'HEAD']);
            }
        };

        $this->failing(fn () => $this->makeGit(after: $moveTheRef)->push());

        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_status_that_fails_after_the_push_went_through_leaves_the_pushed_commit(): void
    {
        $this->changeTheCheckout();
        $pushed = false;
        $statusFailsOncePushed = function (string $command) use (&$pushed): void {
            $pushed = $pushed || str_contains($command, ' push origin ');
            if ($pushed && str_contains($command, ' status --porcelain')) {
                throw new GitException('fatal: status failed', 400);
            }
        };

        $this->failing(fn () => $this->makeGit(after: $statusFailsOncePushed)->push());

        $this->assertSame(trim($this->git($this->origin, ['rev-parse', 'main'])), $this->head($this->checkout));
        $this->assertSame('', $this->git($this->checkout, ['status', '--porcelain']));
        $this->assertChangesKept();
    }

    public function test_a_force_pull_that_fails_after_the_reset_puts_head_and_the_tracked_files_back(): void
    {
        $before = $this->head($this->checkout);
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $this->failing(fn () => $this->makeGit(after: $this->failingAfter(' clean -fd'))->pull('force'));

        $this->assertSame($before, $this->head($this->checkout));
        $this->assertSame('v1', $this->readTrimmed($this->checkout.'/index.php'));
        $this->assertSame('', $this->git($this->checkout, ['status', '--porcelain']));
    }

    /** A tracked edit, a deletion, a new file in a new directory and an empty directory. */
    private function changeTheCheckout(): void
    {
        file_put_contents($this->checkout.'/index.php', "local edit\n");
        unlink($this->checkout.'/old.php');
        mkdir($this->checkout.'/uploads');
        file_put_contents($this->checkout.'/uploads/new.txt', "new\n");
        mkdir($this->checkout.'/cache/sessions', 0755, true);
    }

    private function assertChangesKept(): void
    {
        $this->assertSame('local edit', $this->readTrimmed($this->checkout.'/index.php'));
        $this->assertFileDoesNotExist($this->checkout.'/old.php');
        $this->assertSame('new', $this->readTrimmed($this->checkout.'/uploads/new.txt'));
        $this->assertDirectoryExists($this->checkout.'/cache/sessions');
    }

    /**
     * HEAD and git's own view of the tree.
     *
     * @return array{head: string, status: string}
     */
    private function snapshot(): array
    {
        return [
            'head' => $this->head($this->checkout),
            'status' => $this->git($this->checkout, ['status', '--porcelain', '--untracked-files=all']),
        ];
    }

    private function refuseEveryPush(): void
    {
        $hook = $this->origin.'/hooks/pre-receive';
        file_put_contents($hook, "#!/bin/sh\necho 'pushes are declined here' >&2\nexit 1\n");
        chmod($hook, 0755);
    }

    private function failing(callable $operation): GitException
    {
        try {
            $operation();
        } catch (GitException $e) {
            return $e;
        }

        $this->fail('Expected git to refuse');
    }

    /**
     * A work tree over the real git, with $after called on each command that ran: it may fail it after the fact, as a
     * command that did its work and still reported an error.
     *
     * @param  (callable(string): void)|null  $after
     */
    private function makeGit(?string $checkout = null, ?callable $after = null, ?string $origin = null): TestableProjectGit
    {
        $model = new ModelsUser;
        $model->username = 'tester';
        $model->putSiteGit('', [
            'repo_url' => $origin ?? $this->origin,
            'branch' => 'main',
            'token' => null,
        ]);

        return new TestableProjectGit(
            new Project(new System, $model),
            'public_html',
            function (array $argv, ?string $token, int $timeout = 600) use ($after): string {
                $p = new Process($argv);
                $p->setTimeout($timeout);
                $p->run();
                if (! $p->isSuccessful()) {
                    throw new GitException($p->getErrorOutput() ?: $p->getOutput(), 400);
                }
                if ($after !== null) {
                    $after(implode(' ', $argv));
                }

                return $p->getOutput();
            },
            $checkout ?? $this->checkout,
        );
    }

    /** @return callable(string): void */
    private function failingAfter(string $command): callable
    {
        return function (string $ran) use ($command): void {
            if (str_contains($ran, $command)) {
                throw new GitException("fatal: {$command} failed after it ran", 400);
            }
        };
    }

    /**
     * @param  array<string, string>  $files  path => contents
     */
    private function pushFromAuthor(array $files, string $message): void
    {
        $this->commit($this->author, $files, $message);
        $this->git($this->author, ['push', 'origin', 'main']);
    }

    /**
     * @param  array<string, string>  $files  path => contents
     */
    private function commit(string $repo, array $files, string $message): void
    {
        foreach ($files as $path => $contents) {
            file_put_contents($repo.'/'.$path, $contents);
        }
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['commit', '-m', $message]);
    }

    private function configure(string $repo): void
    {
        $this->git($repo, ['config', 'user.email', 'test@example.com']);
        $this->git($repo, ['config', 'user.name', 'Test User']);
        $this->git($repo, ['config', 'core.autocrlf', 'false']);
    }

    private function head(string $repo): string
    {
        return trim($this->git($repo, ['rev-parse', 'HEAD']));
    }

    private function readTrimmed(string $file): string
    {
        return trim((string) file_get_contents($file));
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $cwd, array $args): string
    {
        $p = new Process(['git', ...$args], $cwd);
        $p->run();
        if (! $p->isSuccessful()) {
            throw new \RuntimeException('git '.implode(' ', $args).': '.($p->getErrorOutput() ?: $p->getOutput()));
        }

        return $p->getOutput();
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDir($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
