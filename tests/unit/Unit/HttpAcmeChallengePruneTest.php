<?php

namespace Tests\Unit;

use App\Lib\HttpAcmeChallengeStore;
use App\System;
use App\System\Privileged;
use Symfony\Component\Process\Process;
use Tests\Support\FakeProcess;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\TestCase;

/** Pins prune(): what it runs on the host, in order, and which domains it reports for a rebuild. */
class HttpAcmeChallengePruneTest extends TestCase
{
    use InMemoryDatabase;

    private const BASE = '/engine/data/webserver/acme-challenges';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    /**
     * @param  list<string>  $dirs
     * @param  array<string, string>  $listings  directory => `files.listAllNames` output
     */
    private static function host(array $dirs, array $listings, int $baseExit = 0): System
    {
        $system = new class extends System
        {
            /** @var list<string> */
            public array $dirs = [];

            /** @var array<string, string> */
            public array $listings = [];

            public int $baseExit = 0;

            /** @var list<array<string>|string> */
            public array $commands = [];

            public function engineDirPath(): string
            {
                return '/engine';
            }

            public function directoryExists(string $path): bool
            {
                return in_array($path, $this->dirs, true);
            }

            public function runProcess(string|array $cmd, array $env = [], int $timeout = 600): Process
            {
                $this->commands[] = $cmd;
                if (is_array($cmd) && LocalRoot::action($cmd) === 'files.listAllNames') {
                    $dir = LocalRoot::values($cmd)[0];
                    if ($dir === '/engine/data/webserver/acme-challenges' && $this->baseExit !== 0) {
                        return FakeProcess::failed('ls failed', $this->baseExit);
                    }

                    return FakeProcess::ok($this->listings[$dir] ?? '');
                }

                return FakeProcess::ok();
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;

                return '';
            }
        };
        $system->dirs = $dirs;
        $system->listings = $listings;
        $system->baseExit = $baseExit;

        return $system;
    }

    public function test_nothing_runs_without_the_challenge_directory(): void
    {
        $system = self::host([], []);

        $this->assertSame([], (new HttpAcmeChallengeStore($system))->prune());
        $this->assertSame([], $system->commands);
    }

    public function test_a_failed_listing_reports_nothing_after_the_expired_files_go(): void
    {
        $system = self::host([self::BASE], [], 2);

        $this->assertSame([], (new HttpAcmeChallengeStore($system))->prune(2));
        $this->assertSame([
            Privileged::files()->deleteFilesTwoLevelsDownOlderThan(self::BASE, 120),
            Privileged::files()->listAllNames(self::BASE),
        ], $system->commands);
    }

    public function test_emptied_directories_are_removed_and_known_domains_reported(): void
    {
        $this->makeMainDomain($this->makeUser('alice'), 'empty.test');
        $this->makeMainDomain($this->makeUser('bob'), 'kept.test');
        $system = self::host(
            [self::BASE, self::BASE.'/kept.test', self::BASE.'/empty.test', self::BASE.'/orphan.test'],
            [
                self::BASE => "kept.test\n.\n\ngone.test\nempty.test\norphan.test\n",
                self::BASE.'/kept.test' => "tok_1\n",
                self::BASE.'/empty.test' => "not a token!\n",
                self::BASE.'/orphan.test' => '',
            ]
        );

        $this->assertSame(['empty.test'], (new HttpAcmeChallengeStore($system))->prune(0));
        $this->assertSame([
            Privileged::files()->deleteFilesTwoLevelsDownOlderThan(self::BASE, 1),
            Privileged::files()->listAllNames(self::BASE),
            Privileged::files()->listAllNames(self::BASE.'/kept.test'),
            Privileged::files()->listAllNames(self::BASE.'/empty.test'),
            Privileged::files()->removeTree(self::BASE.'/empty.test'),
            Privileged::files()->listAllNames(self::BASE.'/orphan.test'),
            Privileged::files()->removeTree(self::BASE.'/orphan.test'),
        ], $system->commands);
    }

    public function test_the_default_ttl_is_a_day(): void
    {
        $system = self::host([self::BASE], [self::BASE => '']);

        $this->assertSame([], (new HttpAcmeChallengeStore($system))->prune());
        $this->assertSame(Privileged::files()->deleteFilesTwoLevelsDownOlderThan(self::BASE, 1440), $system->commands[0]);
    }
}
