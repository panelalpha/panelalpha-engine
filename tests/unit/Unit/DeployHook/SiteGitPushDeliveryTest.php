<?php

namespace Tests\Unit\DeployHook;

use App\Lib\DeployHook\DeliveryRunner;
use App\Models\HookDelivery;
use App\Models\User;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use Symfony\Component\Process\Process;
use Tests\Unit\System\Project\TestableProjectGit;

/**
 * A push to a Site Git checkout (`public_html` of a WordPress project), from
 * the queued delivery down to git, against a real bare origin and a real
 * checkout: what git does with the files is the behaviour under test, so
 * nothing about the working tree is faked.
 *
 * Only Docker is: a Site Git checkout must never reach it, and the spy says
 * whether it did.
 */
class SiteGitPushDeliveryTest extends DeployHookTestCase
{
    private string $root;

    private string $origin;

    private string $checkout;

    private string $author;

    private SpyCheckoutRedeploy $redeploy;

    private ?User $account = null;

    private ?int $hookId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/pa-site-push-' . bin2hex(random_bytes(8));
        $this->origin = $this->root . '/origin.git';
        $this->checkout = $this->root . '/public_html';
        $this->author = $this->root . '/author';
        mkdir($this->root, 0700, true);

        $this->git($this->root, ['init', '--bare', '-b', 'main', $this->origin]);
        // autocrlf is set per clone: a global `true` (Windows git) would check
        // files out with CRLF and make every one look modified afterwards.
        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', $this->origin, $this->author]);
        $this->configure($this->author);
        $this->git($this->author, ['checkout', '-B', 'main']);
        $this->commit($this->author, ['index.php' => "v1\n", 'wp-config.php' => "config\n"], 'Initial commit');
        $this->git($this->author, ['push', 'origin', 'main']);

        $this->git($this->root, ['clone', '-c', 'core.autocrlf=false', '-b', 'main', $this->origin, $this->checkout]);
        $this->configure($this->checkout);

        $this->redeploy = new SpyCheckoutRedeploy();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
        parent::tearDown();
    }

    public function test_a_push_fast_forwards_the_checkout_and_leaves_uploads_alone(): void
    {
        mkdir($this->checkout . '/wp-content/uploads', 0700, true);
        file_put_contents($this->checkout . '/wp-content/uploads/photo.jpg', 'binary');
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $delivery = $this->deliver();

        $this->assertSame(HookDelivery::RESULT_DEPLOYED, $delivery->result);
        $this->assertNull($delivery->detail);
        $this->assertSame($this->headOf($this->author), $this->headOf($this->checkout));
        $this->assertSame('v2', $this->readTrimmed($this->checkout . '/index.php'));
        $this->assertSame('binary', file_get_contents($this->checkout . '/wp-content/uploads/photo.jpg'));
    }

    public function test_a_local_edit_to_a_file_the_push_touches_is_refused_and_the_tree_is_left_as_it_was(): void
    {
        file_put_contents($this->checkout . '/index.php', "edited on the server\n");
        file_put_contents($this->checkout . '/uploads.txt', "keep-me\n");
        $before = $this->headOf($this->checkout);
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');

        $delivery = $this->deliver();

        $this->assertSame(HookDelivery::RESULT_PULL_REFUSED, $delivery->result);
        $this->assertStringContainsString('index.php', (string) $delivery->detail);
        // Only the file that blocks it is named: the untouched ones did not.
        $this->assertStringNotContainsString('wp-config.php', (string) $delivery->detail);
        $this->assertSame($before, $this->headOf($this->checkout));
        $this->assertSame('edited on the server', $this->readTrimmed($this->checkout . '/index.php'));
        $this->assertSame('keep-me', $this->readTrimmed($this->checkout . '/uploads.txt'));
    }

    public function test_an_untracked_file_where_the_push_adds_one_is_refused_naming_it(): void
    {
        file_put_contents($this->checkout . '/robots.txt', "mine\n");
        $this->pushFromAuthor(['robots.txt' => "theirs\n"], 'Add robots.txt');

        $delivery = $this->deliver();

        $this->assertSame(HookDelivery::RESULT_PULL_REFUSED, $delivery->result);
        $this->assertStringContainsString('robots.txt', (string) $delivery->detail);
        $this->assertSame('mine', $this->readTrimmed($this->checkout . '/robots.txt'));
    }

    public function test_history_the_client_rewrote_is_refused_too(): void
    {
        $before = $this->headOf($this->checkout);
        $this->git($this->author, ['commit', '--amend', '-m', 'Rewritten', '--allow-empty']);
        $this->git($this->author, ['push', '--force', 'origin', 'main']);

        $delivery = $this->deliver();

        $this->assertSame(HookDelivery::RESULT_PULL_REFUSED, $delivery->result, (string) $delivery->detail);
        $this->assertStringContainsStringIgnoringCase('diverged', (string) $delivery->detail);
        $this->assertSame($before, $this->headOf($this->checkout));
    }

    public function test_a_site_git_checkout_is_never_rebuilt(): void
    {
        $this->pushFromAuthor(['index.php' => "v2\n"], 'Second commit');
        $this->deliver();

        file_put_contents($this->checkout . '/index.php', "edited on the server\n");
        $this->pushFromAuthor(['index.php' => "v3\n"], 'Third commit');
        $this->deliver();

        // Not merely "no rebuild happened" (a Site Git checkout would be
        // skipped inside afterMutation anyway): a rebuild was never asked for.
        $this->assertSame(0, $this->redeploy->requests, 'neither the deployed push nor the refused one may ask for a rebuild');
        $this->assertSame([], $this->redeploy->calls);
    }

    public function test_a_remote_that_cannot_be_reached_is_a_failure_not_a_refusal(): void
    {
        $this->git($this->checkout, ['remote', 'set-url', 'origin', $this->root . '/nowhere.git']);

        $delivery = $this->deliver();

        $this->assertSame(HookDelivery::RESULT_DEPLOY_FAILED, $delivery->result);
    }

    private function deliver(): HookDelivery
    {
        if ($this->account === null) {
            $this->account = $this->user('main', ['git_repo' => '']);
            // The pull reads its remote and branch from what the project recorded.
            $this->account->putSiteGit('', ['repo_url' => $this->origin, 'branch' => 'main', 'token' => null]);
            $this->hookId = $this->hook($this->account, pathKey: 'public_html')->id;
        }
        $delivery = HookDelivery::create([
            'deploy_hook_id' => $this->hookId,
            'provider' => 'github',
            'delivery_id' => 'guid-site-' . bin2hex(random_bytes(4)),
            'event' => 'push',
            'branch' => 'main',
            'commit' => str_repeat('a', 40),
            'outcome' => HookDelivery::OUTCOME_QUEUED,
        ]);

        (new DeliveryRunner(new TestableGitCheckoutSync($this->redeploy, $this->checkoutOf($this->account))))->run($delivery);

        return $delivery->fresh();
    }

    private function checkoutOf(User $user): TestableProjectGit
    {
        return new TestableProjectGit(
            new Project(new System(), $user),
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
            $this->checkout,
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

    private function headOf(string $repo): string
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
