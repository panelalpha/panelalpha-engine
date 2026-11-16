<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Users\RecoverDind;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A recovery that found the account healthy must not redeploy it: that drops the files written into ~/project. */
class RecoverDindCommandTest extends TestCase
{
    use InMemoryDatabase;

    private const HEALTHY = "[recover-sysbox-dind] alice is up and its inner docker answers; nothing to recover\n";

    private const RECOVERED = "[recover-sysbox-dind] recovering alice id=0123456789ab (D=0)\n"
        . "[recover-sysbox-dind] inner docker healthy after recreate\n"
        . "[recover-sysbox-dind] done alice (D=0)\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->makeUser('alice', ['template' => 'dind']);
        SpyRecoverDind::$rebuilt = [];
        $this->app[Kernel::class]->registerCommand(new SpyRecoverDind());
    }

    public function test_recreate_on_a_healthy_account_rebuilds_nothing_and_says_it_was_skipped(): void
    {
        SpyRecoverDind::$brokerSays = self::HEALTHY;

        $exit = Artisan::call('project:dind:recover', ['--project' => 'alice', '--recreate' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertSame([], SpyRecoverDind::$rebuilt);
        $this->assertStringContainsString('--recreate skipped: `alice` is healthy, so nothing was recreated, wiped or rebuilt.', $output);
        $this->assertStringNotContainsString('Rebuilding', $output);
    }

    public function test_both_options_are_named_when_both_were_skipped(): void
    {
        SpyRecoverDind::$brokerSays = self::HEALTHY;

        Artisan::call('project:dind:recover', ['--project' => 'alice', '--recreate' => true, '--wipe-inner' => true]);

        $this->assertStringContainsString('--wipe-inner and --recreate skipped: `alice` is healthy', Artisan::output());
        $this->assertSame([], SpyRecoverDind::$rebuilt);
    }

    public function test_a_healthy_account_without_options_says_nothing_more(): void
    {
        SpyRecoverDind::$brokerSays = self::HEALTHY;

        Artisan::call('project:dind:recover', ['--project' => 'alice']);

        $this->assertStringNotContainsString('skipped', Artisan::output());
    }

    public function test_recreate_after_a_real_recovery_still_refreshes_the_project(): void
    {
        SpyRecoverDind::$brokerSays = self::RECOVERED;

        $exit = Artisan::call('project:dind:recover', ['--project' => 'alice', '--recreate' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(['alice'], SpyRecoverDind::$rebuilt);
        $this->assertStringContainsString('Rebuilding project `alice` config...', Artisan::output());
    }

    public function test_another_account_done_is_not_this_one_recovered(): void
    {
        $this->makeUser('al', ['template' => 'dind']);
        SpyRecoverDind::$brokerSays = "[recover-sysbox-dind] al is up and its inner docker answers; nothing to recover\n"
            . "[recover-sysbox-dind] done alice (D=0)\n";

        Artisan::call('project:dind:recover', ['--project' => 'al', '--recreate' => true]);

        $this->assertSame([], SpyRecoverDind::$rebuilt);
    }
}

/** @internal */
class SpyRecoverDind extends RecoverDind
{
    public static string $brokerSays = '';

    /** @var list<string> */
    public static array $rebuilt = [];

    protected function recoveryProcess(array $argv): Process
    {
        return new Process(['printf', '%s', self::$brokerSays]);
    }

    protected function rebuild(User $user): void
    {
        self::$rebuilt[] = $user->username;
    }
}
