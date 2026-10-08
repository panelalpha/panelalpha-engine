<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The `ff` pull is refused when git refuses it, and only then. Every case runs
 * against a real bare origin and a real checkout, because the behaviour under
 * test is git's own: what it treats as a conflict, and that it leaves the tree
 * alone when it says no.
 */
class ProjectGitFastForwardPullTest extends TestCase
{
    private string $root;

    private string $origin;

    private string $checkout;

    private string $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-git-ff-' . bin2hex(random_bytes(8));
        $this->origin = $this->root . '/origin.git';
        $this->checkout = $this->root . '/checkout';
        $this->author = $this->root . '/author';
        mkdir($this->root, 0700, true);

        $this->git($this->root, ['init', '--bare', '-b', 'main', $this->origin]);

        // A second clone plays the developer who pushes to the remote.
        // autocrlf is set per clone command: a global `true` (Windows git) would
        // check files out with CRLF and make every one look modified afterwards.
        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', $this->origin, $this->author]);
        $this->configure($this->author);
        $this->git($this->author, ['checkout', '-B', 'main']);
        $this->commit($this->author, ['index.php' => "v1\n", 'config.php' => "config\n"], 'Initial commit');
        $this->git($this->author, ['push', 'origin', 'main']);

        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', '-b', 'main', $this->origin, $this->checkout]);
        $this->configure($this->checkout);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_untracked_files_do_not_block_a_fast_forward(): void
    {
        mkdir($this->checkout . '/uploads');
        file_put_contents($this->checkout . '/uploads/photo.jpg', 'binary');
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $status = $this->pull();

        $this->assertSame($this->head($this->author), $this->head($this->checkout));
        $this->assertSame('v2', $this->readTrimmed($this->checkout . '/index.php'));
        $this->assertSame('binary', file_get_contents($this->checkout . '/uploads/photo.jpg'));
        $this->assertSame(0, $status['commits_behind']);
    }

    public function test_local_edit_to_a_file_the_incoming_commits_leave_alone_does_not_block(): void
    {
        file_put_contents($this->checkout . '/config.php', "local\n");
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $this->pull();

        $this->assertSame($this->head($this->author), $this->head($this->checkout));
        $this->assertSame('local', $this->readTrimmed($this->checkout . '/config.php'));
    }

    public function test_local_edit_to_a_file_the_incoming_commit_changes_is_refused_naming_the_path(): void
    {
        file_put_contents($this->checkout . '/index.php', "local edit\n");
        file_put_contents($this->checkout . '/uploads.txt', "keep-me\n");
        $before = $this->head($this->checkout);
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $e = $this->refusedPull();

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('index.php', $e->getMessage());
        $this->assertSame($before, $this->head($this->checkout));
        $this->assertSame('local edit', $this->readTrimmed($this->checkout . '/index.php'));
        $this->assertSame('keep-me', $this->readTrimmed($this->checkout . '/uploads.txt'));
    }

    public function test_untracked_file_at_a_path_the_incoming_commit_adds_is_refused_naming_the_path(): void
    {
        file_put_contents($this->checkout . '/robots.txt', "mine\n");
        $before = $this->head($this->checkout);
        $this->pushFromAuthor(['robots.txt' => "theirs\n"], 'Add robots.txt');

        $e = $this->refusedPull();

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('robots.txt', $e->getMessage());
        $this->assertSame($before, $this->head($this->checkout));
        $this->assertSame('mine', $this->readTrimmed($this->checkout . '/robots.txt'));
    }

    public function test_untracked_file_where_the_incoming_commit_needs_a_directory_is_refused(): void
    {
        file_put_contents($this->checkout . '/docs', "a plain file\n");
        $this->pushFromAuthor(['docs/readme.md' => "hello\n"], 'Add docs/');

        $e = $this->refusedPull();

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('docs/readme.md', $e->getMessage());
        $this->assertSame('a plain file', $this->readTrimmed($this->checkout . '/docs'));
    }

    public function test_untracked_directory_where_the_incoming_commit_adds_a_file_is_refused(): void
    {
        mkdir($this->checkout . '/assets');
        file_put_contents($this->checkout . '/assets/logo.png', 'png');
        $this->pushFromAuthor(['assets' => "now a file\n"], 'assets becomes a file');

        $e = $this->refusedPull();

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsString('assets', $e->getMessage());
        $this->assertSame('png', file_get_contents($this->checkout . '/assets/logo.png'));
    }

    public function test_every_conflicting_path_is_listed(): void
    {
        file_put_contents($this->checkout . '/index.php', "local edit\n");
        file_put_contents($this->checkout . '/robots.txt', "mine\n");
        $this->pushFromAuthor(['index.php' => "v2\n", 'robots.txt' => "theirs\n"], 'Touch both');

        $e = $this->refusedPull();

        $this->assertStringContainsString('index.php', $e->getMessage());
        $this->assertStringContainsString('robots.txt', $e->getMessage());
    }

    public function test_diverged_history_is_refused_with_a_reason_and_leaves_the_checkout_alone(): void
    {
        file_put_contents($this->checkout . '/uploads.txt', "keep-me\n");
        $this->commit($this->checkout, ['local.php' => "local\n"], 'Local commit');
        $before = $this->head($this->checkout);
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Remote commit');

        $e = $this->refusedPull();

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsStringIgnoringCase('diverged', $e->getMessage());
        $this->assertSame($before, $this->head($this->checkout));
        $this->assertFileExists($this->checkout . '/local.php');
        $this->assertSame('keep-me', $this->readTrimmed($this->checkout . '/uploads.txt'));
    }

    public function test_history_rewritten_by_a_force_push_counts_as_diverged(): void
    {
        $before = $this->head($this->checkout);
        $this->git($this->author, ['commit', '--amend', '-m', 'Rewritten', '--allow-empty']);
        $this->git($this->author, ['push', '--force', 'origin', 'main']);

        $e = $this->refusedPull();

        $this->assertSame(422, $e->httpStatus);
        $this->assertStringContainsStringIgnoringCase('diverged', $e->getMessage());
        $this->assertSame($before, $this->head($this->checkout));
    }

    public function test_checkout_without_commits_reports_a_path_conflict_not_divergence(): void
    {
        $empty = $this->root . '/empty';
        mkdir($empty);
        $this->git($empty, ['init', '-b', 'main']);
        $this->git($empty, ['remote', 'add', 'origin', $this->origin]);
        file_put_contents($empty . '/index.php', "mine\n");

        try {
            $this->makeGit($empty)->pull();
            $this->fail('Expected the fast-forward pull to be refused');
        } catch (GitException $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertStringContainsString('index.php', $e->getMessage());
            $this->assertStringNotContainsStringIgnoringCase('diverged', $e->getMessage());
        }
        $this->assertSame('mine', $this->readTrimmed($empty . '/index.php'));
    }

    public function test_checkout_without_commits_fast_forwards_around_untracked_files(): void
    {
        $empty = $this->root . '/empty';
        mkdir($empty);
        $this->git($empty, ['init', '-b', 'main']);
        $this->git($empty, ['remote', 'add', 'origin', $this->origin]);
        file_put_contents($empty . '/uploads.txt', "keep-me\n");

        $this->makeGit($empty)->pull();

        $this->assertSame('v1', $this->readTrimmed($empty . '/index.php'));
        $this->assertSame('keep-me', $this->readTrimmed($empty . '/uploads.txt'));
    }

    public function test_already_up_to_date_succeeds(): void
    {
        $before = $this->head($this->checkout);

        $status = $this->pull();

        $this->assertSame($before, $this->head($this->checkout));
        $this->assertSame(0, $status['commits_behind']);
    }

    public function test_force_still_discards_local_edits_and_untracked_files(): void
    {
        file_put_contents($this->checkout . '/index.php', "local edit\n");
        file_put_contents($this->checkout . '/uploads.txt', "gone\n");
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $this->pull('force');

        $this->assertSame($this->head($this->author), $this->head($this->checkout));
        $this->assertSame('v2', $this->readTrimmed($this->checkout . '/index.php'));
        $this->assertFileDoesNotExist($this->checkout . '/uploads.txt');
    }

    public function test_push_first_still_commits_local_changes_and_pushes_them(): void
    {
        file_put_contents($this->checkout . '/config.php', "local\n");
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $this->pull('push_first');

        $this->assertSame('v2', $this->readTrimmed($this->checkout . '/index.php'));
        $this->assertSame('local', $this->readTrimmed($this->checkout . '/config.php'));
        $this->assertSame($this->head($this->checkout), trim($this->git($this->origin, ['rev-parse', 'main'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function pull(?string $strategy = null): array
    {
        return $this->makeGit()->pull($strategy);
    }

    private function refusedPull(): GitException
    {
        try {
            $this->makeGit()->pull();
        } catch (GitException $e) {
            return $e;
        }

        $this->fail('Expected the fast-forward pull to be refused');
    }

    private function makeGit(?string $dir = null): TestableProjectGit
    {
        $model = new ModelsUser();
        $model->username = 'tester';
        $model->putSiteGit('', [
            'repo_url' => $this->origin,
            'branch' => 'main',
            'token' => null,
        ]);

        return new TestableProjectGit(
            new Project(new System(), $model),
            'public_html',
            function (array $argv, ?string $token, int $timeout = 600): string {
                $p = new Process($argv);
                $p->setTimeout($timeout);
                $p->run();
                if (!$p->isSuccessful()) {
                    throw new GitException($p->getErrorOutput() ?: $p->getOutput(), 400);
                }

                return $p->getOutput();
            },
            $dir ?? $this->checkout,
        );
    }

    /**
     * @param array<string, string> $files path => contents
     */
    private function pushFromAuthor(array $files, string $message): void
    {
        $this->commit($this->author, $files, $message);
        $this->git($this->author, ['push', 'origin', 'main']);
    }

    /**
     * @param array<string, string> $files path => contents
     */
    private function commit(string $repo, array $files, string $message): void
    {
        foreach ($files as $path => $contents) {
            if (!is_dir(dirname($repo . '/' . $path))) {
                mkdir(dirname($repo . '/' . $path), 0700, true);
            }
            file_put_contents($repo . '/' . $path, $contents);
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
     * @param list<string> $args
     */
    private function git(string $cwd, array $args): string
    {
        $p = new Process(['git', ...$args], $cwd);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ': ' . ($p->getErrorOutput() ?: $p->getOutput()));
        }

        return $p->getOutput();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDir($path);
            } else {
                @chmod($path, 0666);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
