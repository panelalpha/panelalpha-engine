<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Git\Exception as GitException;
use Tests\TestCase;

/**
 * GitHub's edge at times refuses older git's HTTP/2 fingerprint after the ref
 * listing. Every git call that reads from the remote retries once over
 * HTTP/1.1 then, not only the deploy's clone.
 */
class GitHttp11RetryTest extends TestCase
{
    private const string REFUSED = "error: unable to read askpass response from '/bin/false'\n"
        . "fatal: could not read Username for 'https://github.com': terminal prompts disabled\n"
        . 'fatal: expected flush after ref listing';

    /** The connected branch's fetch, into origin/main: the deploy's clone is single-branch. */
    private const string FETCH_MAIN = 'fetch origin +refs/heads/main:refs/remotes/origin/main';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
    }

    public function test_pull_retries_its_fetch_over_http_1_1(): void
    {
        [$git, $calls] = $this->refusedOnce(self::FETCH_MAIN, $this->connectedUser());

        $git->pull();

        $this->assertRetried($calls(), self::FETCH_MAIN);
    }

    public function test_status_fetch_retries_over_http_1_1(): void
    {
        [$git, $calls] = $this->refusedOnce(self::FETCH_MAIN, $this->connectedUser());

        $git->status(true);

        $this->assertRetried($calls(), self::FETCH_MAIN);
    }

    public function test_connect_retries_its_first_fetch_over_http_1_1(): void
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        [$git, $calls] = $this->refusedOnce('fetch --depth=1 origin main', $model, notARepository: true);

        $status = $git->connect('https://github.com/org/repo.git', 'main', null);

        $this->assertArrayNotHasKey('sync_error', $status);
        $this->assertRetried($calls(), 'fetch --depth=1 origin main');
    }

    public function test_full_history_fetch_retries_over_http_1_1(): void
    {
        [$git, $calls] = $this->refusedOnce('fetch --unshallow --tags origin', $this->connectedUser());

        $git->fetchFullHistory(null);

        $this->assertRetried($calls(), 'fetch --unshallow --tags origin');
    }

    public function test_submodule_fetch_retries_over_http_1_1(): void
    {
        $dir = sys_get_temp_dir() . '/pa-git-retry-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/.gitmodules', "[submodule \"lib\"]\n");
        try {
            [$git, $calls] = $this->refusedOnce('submodule update', $this->connectedUser(), absolutePath: $dir);

            $this->assertTrue($git->initSubmodules());
            $this->assertRetried($calls(), 'submodule update --init --recursive --depth=1');
        } finally {
            unlink($dir . '/.gitmodules');
            rmdir($dir);
        }
    }

    public function test_any_other_failure_is_not_retried(): void
    {
        $calls = [];
        $git = new TestableProjectGit(
            new Project(new System(), $this->connectedUser()),
            'public_html',
            function (array $cmd) use (&$calls): string {
                $calls[] = implode(' ', $cmd);
                if (str_contains(end($calls), self::FETCH_MAIN)) {
                    throw new GitException("fatal: couldn't find remote ref main", 400);
                }

                return str_contains(end($calls), 'rev-parse --is-inside-work-tree') ? "true\n" : '';
            },
        );

        try {
            $git->pull();
            $this->fail('Expected GitException');
        } catch (GitException $e) {
            $this->assertStringContainsString("couldn't find remote ref", $e->getMessage());
        }

        $this->assertCount(1, array_filter($calls, fn (string $c) => str_contains($c, self::FETCH_MAIN)));
        $this->assertSame([], array_filter($calls, fn (string $c) => str_contains($c, 'http.version')));
    }

    /**
     * A work tree whose git fails `$needle` once with the edge's refusal and
     * answers everything else as a clean, connected checkout.
     *
     * @return array{0: TestableProjectGit, 1: callable(): list<list<string>>}
     */
    private function refusedOnce(
        string $needle,
        ModelsUser $model,
        bool $notARepository = false,
        ?string $absolutePath = null,
    ): array {
        $calls = [];
        $refused = false;
        $initialised = false;
        $runner = function (array $cmd) use (&$calls, &$refused, &$initialised, $needle, $notARepository): string {
            $calls[] = $cmd;
            $joined = implode(' ', $cmd);
            if (!$refused && str_contains($joined, $needle)) {
                $refused = true;
                throw new GitException(self::REFUSED, 400);
            }
            if (str_contains($joined, ' init')) {
                $initialised = true;
            }

            return match (true) {
                str_contains($joined, 'rev-parse --is-inside-work-tree') => $notARepository && !$initialised ? '' : "true\n",
                str_contains($joined, 'rev-parse --is-shallow-repository') => "true\n",
                str_contains($joined, 'branch --show-current') => "main\n",
                default => '',
            };
        };

        $git = new TestableProjectGit(new Project(new System(), $model), 'public_html', $runner, $absolutePath);

        return [$git, function () use (&$calls): array {
            return $calls;
        }];
    }

    /**
     * @param list<list<string>> $calls
     */
    private function assertRetried(array $calls, string $needle): void
    {
        $matching = array_values(array_filter($calls, fn (array $c) => str_contains(implode(' ', $c), $needle)));
        $this->assertCount(2, $matching, 'expected the refused command and one retry');
        $this->assertNotContains('http.version=HTTP/1.1', $matching[0]);
        $this->assertSame(['git', '-c', 'http.version=HTTP/1.1'], array_slice($matching[1], 0, 3));
        $this->assertSame(array_slice($matching[0], 1), array_slice($matching[1], 3));
    }

    private function connectedUser(): ModelsUser
    {
        $model = new ModelsUser();
        $model->username = 'alice';
        $model->domain = 'example.com';
        $model->details = [
            'site_git' => [
                'public_html' => [
                    'repo_url' => 'https://github.com/org/repo.git',
                    'branch' => 'main',
                    'token' => null,
                ],
            ],
        ];

        return $model;
    }
}
