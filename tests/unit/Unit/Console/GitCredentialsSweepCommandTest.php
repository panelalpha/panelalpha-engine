<?php

namespace Tests\Unit\Console;

use App\Console\Kernel;
use App\System;
use App\System\Project\Git\CredentialFiles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use ReflectionMethod;
use Tests\TestCase;

/**
 * `git:credentials:sweep` sweeps stale credentials anywhere under a home,
 * including the nested directories a non-DinD work tree is checked out under.
 */
class GitCredentialsSweepCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/pa-git-credential-sweep-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/home/alice', 0700, true);
        mkdir($this->root . '/home/bob', 0700, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_it_sweeps_stale_credentials_from_every_home_and_keeps_fresh_ones(): void
    {
        $staleAskPass = $this->credentialFile('alice', CredentialFiles::ASKPASS_PREFIX . 'deadbeef', "#!/bin/sh\necho ghp_secret\n");
        $staleKey = $this->credentialFile('alice', CredentialFiles::SSH_PREFIX . 'key-cafef00d', "-----BEGIN PRIVATE KEY-----\n");
        $nestedStale = $this->credentialFile('alice', CredentialFiles::SSH_PREFIX . 'nested-key', "-----BEGIN PRIVATE KEY-----\n", subdir: 'example.com');
        $nestedFresh = $this->credentialFile('alice', CredentialFiles::ASKPASS_PREFIX . 'nested-live', "#!/bin/sh\necho ghp_secret\n", fresh: true, subdir: 'example.com');
        $fresh = $this->credentialFile('bob', CredentialFiles::ASKPASS_PREFIX . 'live', "#!/bin/sh\necho ghp_secret\n", fresh: true);
        $ordinary = $this->credentialFile('bob', '.gitconfig', "[user]\n", fresh: true);

        $this->assertSame(0700, fileperms(dirname($nestedStale)) & 0777, 'the nested work-tree directory is account-owned and closed');

        $host = $this->host();
        $this->app->instance(System::class, $host);

        $exit = Artisan::call('git:credentials:sweep');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertFileDoesNotExist($staleAskPass);
        $this->assertFileDoesNotExist($staleKey);
        $this->assertFileDoesNotExist($nestedStale, 'a credential file nested below the home was left behind');
        $this->assertFileExists($nestedFresh, "a running command's nested credentials were deleted");
        $this->assertFileExists($fresh, "a running command's credentials were deleted");
        $this->assertFileExists($ordinary);
        $this->assertContains(['sudo', 'rm', '-f', '--', $nestedStale], $host->removals);
        $this->assertSame(2, count($host->finds), 'one find per home, with no second pass over the directories');
        $this->assertStringNotContainsString('-maxdepth', implode(' ', $host->finds[0] ?? []));
        $this->assertStringContainsString(CredentialFiles::ASKPASS_PREFIX, implode(' ', $host->finds[0] ?? []));
        $this->assertStringContainsString('-mmin', implode(' ', $host->finds[0] ?? []));
        $this->assertContains('-ignore_readdir_race', $host->finds[0] ?? [], 'a file removed mid-walk would fail the whole home');
        $this->assertStringContainsString('Swept 3 stale git credential file(s) from 2 home(s).', $output);
    }

    public function test_a_failed_find_on_one_home_does_not_stop_the_sweep(): void
    {
        $badHome = $this->root . '/home/alice';
        $bobStale = $this->credentialFile('bob', CredentialFiles::ASKPASS_PREFIX . 'bob-stale', "#!/bin/sh\necho ghp_secret\n");

        Log::spy();
        $this->app->instance(System::class, $this->host($badHome));

        $exit = Artisan::call('git:credentials:sweep');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertFileDoesNotExist($bobStale, 'the next home was not swept after one find failed');
        $this->assertStringContainsString('Swept 1 stale git credential file(s) from 2 home(s).', $output);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $context['home'] === $badHome);
    }

    public function test_it_is_scheduled_hourly_without_overlapping(): void
    {
        $schedule = new Schedule();
        (new ReflectionMethod(Kernel::class, 'schedule'))->invoke(app(Kernel::class), $schedule);

        $event = collect($schedule->events())->first(
            static fn ($item): bool => str_contains((string) $item->command, 'git:credentials:sweep')
        );

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function credentialFile(string $user, string $name, string $contents, bool $fresh = false, ?string $subdir = null): string
    {
        $dir = $this->root . '/home/' . $user . ($subdir === null ? '' : '/' . $subdir);
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir . '/' . $name;
        file_put_contents($path, $contents);
        if (! $fresh) {
            touch($path, time() - CredentialFiles::STALE_SECONDS - 60);
        }

        return $path;
    }

    private function host(?string $failOn = null): System
    {
        return new class ($this->root, $failOn) extends System {
            /** @var list<list<string>> */
            public array $removals = [];

            /** @var list<list<string>> */
            public array $finds = [];

            public function __construct(private string $root, private ?string $failOn)
            {
            }

            public function homesDirPath(): string
            {
                return $this->root . '/home';
            }

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $argv = array_values((array) $cmd);
                if (array_slice($argv, 0, 2) === ['sudo', 'find']) {
                    if ($this->failOn !== null && ($argv[2] ?? null) === $this->failOn) {
                        throw new \RuntimeException('find: ' . $argv[2] . ' is unreadable');
                    }
                    $this->finds[] = $argv;

                    return $this->find($argv);
                }
                if (array_slice($argv, 0, 2) === ['sudo', 'rm']) {
                    @unlink((string) end($argv));
                    $this->removals[] = $argv;
                }

                return '';
            }

            /**
             * @param  list<string>  $argv
             */
            private function find(array $argv): string
            {
                $root = rtrim((string) ($argv[2] ?? ''), '/');
                $minutes = 0;
                foreach ($argv as $i => $arg) {
                    if ($arg === '-mmin') {
                        $minutes = (int) ltrim((string) ($argv[$i + 1] ?? ''), '+');
                    }
                }

                if (! is_dir($root)) {
                    return '';
                }

                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                $output = '';
                foreach ($files as $file) {
                    $name = $file->getFilename();
                    if (! $file->isFile()
                        || ($minutes > 0 && $file->getMTime() > time() - $minutes * 60)
                        || (! str_starts_with($name, CredentialFiles::ASKPASS_PREFIX) && ! str_starts_with($name, CredentialFiles::SSH_PREFIX))) {
                        continue;
                    }
                    $output .= $file->getPathname() . "\0";
                }

                return $output;
            }
        };
    }
}
