<?php

namespace Tests\Integration\Console;

use App\Console\Commands\Testing\Coverage;
use App\System;
use App\System\Privileged;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** `pae testing:coverage` with xdebug present: what each action asks the root broker to do with the token's directory. */
class CoverageRecordingCommandTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<array<int, string>|string> */
    private array $commands = [];

    private string $tokenId = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.debug' => true, 'testing.coverage_dir' => '/coverage-root']);
        $commands = &$this->commands;
        $this->app->instance(System::class, new class($commands) extends System
        {
            /** @param list<array<int, string>|string> $commands */
            public function __construct(private array &$commands) {}

            public function exec(string|array $cmd, array $env = [], int $timeout = 600): string
            {
                $this->commands[] = $cmd;

                return '';
            }
        });
        $token = PersonalAccessToken::query()->forceCreate([
            'tokenable_type' => 'admin',
            'tokenable_id' => 1,
            'name' => 'coverage',
            'token' => hash('sha256', 'coverage-token'),
            'abilities' => ['*'],
        ]);
        $this->tokenId = (string) $token->getKey();
    }

    /** @param array<string, mixed> $options */
    private function coverage(array $options): array
    {
        $command = new class extends Coverage
        {
            protected function xdebugLoaded(): bool
            {
                return true;
            }
        };
        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $code = $command->run(new ArrayInput($options), $output);

        return [$code, $output->fetch()];
    }

    public function test_an_unknown_token_is_refused_before_anything_runs(): void
    {
        [$code, $out] = $this->coverage(['--api-token-id' => '999', '--start-recording' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Invalid API token ID', $out);
        $this->assertSame([], $this->commands);
    }

    public function test_starting_makes_the_dump_directory_for_the_web_user_and_marks_it_recording(): void
    {
        [$code, $out] = $this->coverage(['--api-token-id' => $this->tokenId, '--start-recording' => true]);

        $dir = "/coverage-root/{$this->tokenId}";
        $this->assertSame(0, $code);
        $this->assertStringContainsString("Started coverage recording for token {$this->tokenId}", $out);
        $this->assertSame([
            Privileged::files()->makeDirectories($dir.'/dumps'),
            Privileged::files()->changeOwnerRecursively('www-data:www-data', $dir.'/dumps'),
            Privileged::files()->touch($dir.'/recording'),
        ], $this->commands);
    }

    public function test_stopping_removes_only_the_recording_mark(): void
    {
        [$code, $out] = $this->coverage(['--api-token-id' => $this->tokenId, '--stop-recording' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Stopped coverage recording for token {$this->tokenId}", $out);
        $this->assertSame([Privileged::files()->removeTree("/coverage-root/{$this->tokenId}/recording")], $this->commands);
    }

    public function test_cleanup_removes_the_whole_token_directory(): void
    {
        [$code, $out] = $this->coverage(['--api-token-id' => $this->tokenId, '--cleanup' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString("Removed files related to token {$this->tokenId}", $out);
        $this->assertSame([Privileged::files()->removeTree("/coverage-root/{$this->tokenId}")], $this->commands);
    }
}
