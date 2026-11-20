<?php

namespace Tests\Integration\Project;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Lib\Project\ProjectCopier;
use App\Models\User;
use App\System;
use App\System\Project as SystemProject;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The copy a CopyProject task runs: both rows found by id, every step logged, and a failure thrown with its reason;
 * a copy's domain is refused when no free name is left for it.
 */
class ProjectCopierTaskTest extends TestCase
{
    use InMemoryDatabase;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_copy_that_no_longer_exists_is_not_found(): void
    {
        $source = $this->makeUser('shop');

        $this->expectException(NotFoundException::class);
        $this->expectExceptionMessage('The copy no longer exists.');

        (new ProjectCopier)->copyInto(999, $source->id, true, $this->logLine(...));
    }

    public function test_a_copy_whose_source_is_gone_says_which_copy_it_was(): void
    {
        $copy = $this->makeUser('shopcopy');

        try {
            (new ProjectCopier)->copyInto($copy->id, 999, true, $this->logLine(...));
            $this->fail('the copy went ahead');
        } catch (NotFoundException $e) {
            $this->assertSame("The project 'shopcopy' was being copied from no longer exists.", $e->getMessage());
        }
        $this->assertSame([], $this->log);
    }

    public function test_the_task_log_names_the_copy_its_warnings_and_its_end(): void
    {
        $source = $this->makeUser('shop');
        $copy = $this->makeUser('shopcopy');
        $copier = Mockery::mock(ProjectCopier::class)->makePartial();
        $copier->expects('copy')
            ->withArgs(static fn (User $from, User $to, bool $databases): bool => $from->is($source) && $to->is($copy) && $databases === false)
            ->andReturn([$copy, ['Subdomains are not copied', 'FTP accounts are not copied']]);

        $copier->copyInto($copy->id, $source->id, false, $this->logLine(...));

        $this->assertSame([
            'Copying project shop into shopcopy',
            'Subdomains are not copied',
            'FTP accounts are not copied',
            'Copy finished',
        ], $this->log);
    }

    public function test_a_refused_copy_is_thrown_with_every_reason_joined(): void
    {
        $source = $this->makeUser('shop');
        $copy = $this->makeUser('shopcopy');
        $refusal = ValidationException::withMessages(['domain' => ['taken', 'reserved'], 'disk' => 'full']);
        $copier = Mockery::mock(ProjectCopier::class)->makePartial();
        $copier->expects('copy')->andThrow($refusal);

        try {
            $copier->copyInto($copy->id, $source->id, true, $this->logLine(...));
            $this->fail('the refusal was swallowed');
        } catch (RuntimeException $e) {
            $this->assertSame('taken | reserved | full', $e->getMessage());
            $this->assertSame($refusal, $e->getPrevious());
        }
        $this->assertSame(['Copying project shop into shopcopy'], $this->log);
    }

    public function test_discarding_a_copy_already_gone_does_nothing(): void
    {
        (new ProjectCopier)->discard(999);

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_failure_destroying_the_half_made_copy_does_not_hide_why_the_copy_failed(): void
    {
        $source = new class extends User {
            public function hasGitProject(): bool
            {
                throw new RuntimeException('the source vanished mid-copy');
            }
        };
        $source->forceFill(['username' => 'shop']);
        $copy = new class extends User {
            public SystemProject $hosting;

            public function project(?System $system = null): SystemProject
            {
                return $this->hosting;
            }
        };
        $copy->forceFill(['username' => 'shopcopy']);
        $copy->hosting = Mockery::mock(SystemProject::class);
        $copy->hosting->expects('destroy')->andThrow(new RuntimeException('docker is down'));

        try {
            (new ProjectCopier)->copy($source, $copy, true);
            $this->fail('the copy went through');
        } catch (ProblemException $e) {
            $this->assertSame('clone_failed', $e->problems[0]['code']);
            $this->assertStringContainsString('the source vanished mid-copy', $e->problems[0]['message']);
        }
    }

    public function test_a_clone_with_no_free_name_left_is_refused(): void
    {
        $owner = $this->makeUser('holder', [], 'holder.example.test');
        $rows = [['user_id' => $owner->id, 'domain' => 'clone.shop.example.test', 'type' => 'addon', 'details' => '{}']];
        foreach (range(1000, 9999) as $suffix) {
            $rows[] = ['user_id' => $owner->id, 'domain' => "clone{$suffix}.shop.example.test", 'type' => 'addon', 'details' => '{}'];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('domains')->insert($chunk);
        }
        $source = $this->makeUser('shop', [], 'shop.example.test');

        try {
            (new ReflectionMethod(ProjectCopier::class, 'copyDomain'))->invoke(new ProjectCopier, $source, null, 'shopcopy');
            $this->fail('a taken name was planned');
        } catch (ValidationException $e) {
            $this->assertSame(['domain' => ['Could not generate an available name for the clone.']], $e->errors());
        }
    }

    private function logLine(string $line): void
    {
        $this->log[] = $line;
    }
}
