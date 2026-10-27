<?php

namespace Tests\Unit\Limits;

use App\Http\Requests\UserUpdateRequest;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\NewProjectRules;
use App\Rules\UnlimitedOrAtLeastZero;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** What the REST create and update rules (and the MCP tools on them) take for each limit. */
class LimitRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HostMemoryProbe::fake(new HostMemory(3809));
    }

    protected function tearDown(): void
    {
        HostMemoryProbe::fake(null);
        parent::tearDown();
    }

    #[DataProvider('everyRuleSet')]
    public function test_every_limit_but_memory_accepts_minus_one(string $ruleSet): void
    {
        foreach (self::limitsBut('memory_limit') as $key) {
            $this->assertTrue($this->passes($ruleSet, $key, -1), "{$ruleSet} {$key} refused -1");
        }
    }

    #[DataProvider('everyRuleSet')]
    public function test_below_minus_one_is_refused(string $ruleSet): void
    {
        foreach (self::limitsBut('memory_limit') as $key) {
            $this->assertFalse($this->passes($ruleSet, $key, -2), "{$ruleSet} {$key} took -2");
        }
    }

    #[DataProvider('everyRuleSet')]
    public function test_a_value_between_minus_one_and_zero_is_refused(string $ruleSet): void
    {
        foreach (self::limitsBut('memory_limit') as $key) {
            foreach ([-0.5, -0.001, -1.5, '-0.5'] as $value) {
                $this->assertFalse($this->passes($ruleSet, $key, $value), "{$ruleSet} {$key} took {$value}");
            }
        }
    }

    #[DataProvider('everyRuleSet')]
    public function test_zero_and_a_real_value_are_accepted(string $ruleSet): void
    {
        foreach (self::limitsBut('memory_limit') as $key) {
            $this->assertTrue($this->passes($ruleSet, $key, 0), "{$ruleSet} {$key} refused 0");
            $this->assertTrue($this->passes($ruleSet, $key, 1000), "{$ruleSet} {$key} refused 1000");
            $this->assertTrue($this->passes($ruleSet, $key, '-1'), "{$ruleSet} {$key} refused '-1'");
        }
        $this->assertTrue($this->passes($ruleSet, 'cpu_limit', 0.5));
        $this->assertTrue($this->passes($ruleSet, 'cpu_limit', -1.0));
    }

    #[DataProvider('everyRuleSet')]
    public function test_a_refused_limit_says_what_it_takes(string $ruleSet): void
    {
        $rules = $this->rules($ruleSet);
        $rule = collect($rules['cpu_limit'])->first(static fn (mixed $r): bool => $r instanceof UnlimitedOrAtLeastZero);

        $this->assertFalse(Validator::make(['cpu_limit' => -0.5], ['cpu_limit' => $rules['cpu_limit']])->passes());
        $this->assertSame([
            'field' => 'cpu_limit',
            'code' => 'cpu_limit_min',
            'message' => 'The cpu limit must be -1 for unlimited, or 0 or more.',
            'expected' => '-1 for unlimited, or 0 or more',
            'examples' => ['-1', '0'],
        ], $rule->problem());
    }

    #[DataProvider('everyRuleSet')]
    public function test_null_still_means_not_set(string $ruleSet): void
    {
        foreach (self::limitsBut('memory_limit') as $key) {
            $this->assertTrue($this->passes($ruleSet, $key, null), "{$ruleSet} {$key} refused null");
        }
    }

    #[DataProvider('everyRuleSet')]
    public function test_memory_refuses_minus_one_and_takes_a_real_value(string $ruleSet): void
    {
        $this->assertFalse($this->passes($ruleSet, 'memory_limit', -1));
        $this->assertFalse($this->passes($ruleSet, 'memory_limit', 0));
        $this->assertTrue($this->passes($ruleSet, 'memory_limit', 512));
    }

    /** Docker refuses a container less than 6 MB: the create was accepted and failed when the account started. */
    #[DataProvider('everyRuleSet')]
    public function test_memory_below_dockers_minimum_is_refused_with_one_message(string $ruleSet): void
    {
        $rules = $this->rules($ruleSet);

        $this->assertTrue($this->passes($ruleSet, 'memory_limit', 6));
        foreach ([5, 4, 0, -1] as $mb) {
            $errors = Validator::make(['memory_limit' => $mb], ['memory_limit' => $rules['memory_limit']])->errors();
            $this->assertSame(
                ["The memory limit must be at least 6 MB, the least Docker starts a container with ({$mb} MB requested)."],
                $errors->get('memory_limit'),
                "{$ruleSet} {$mb}"
            );
        }
    }

    public function test_a_create_makes_a_container_project_when_it_names_dind_or_a_repository(): void
    {
        $this->assertTrue(NewProjectRules::createsContainer(['template' => 'dind']));
        $this->assertTrue(NewProjectRules::createsContainer(['git_repo' => 'github.com/o/r']));
        $this->assertFalse(NewProjectRules::createsContainer(['template' => 'default', 'git_repo' => ' ']));
        $this->assertFalse(NewProjectRules::createsContainer([]));
    }

    /** @return array<string, array{string}> */
    public static function everyRuleSet(): array
    {
        return ['create' => ['create'], 'update' => ['update']];
    }

    /** @return list<string> */
    private static function limitsBut(string $except): array
    {
        return array_values(array_diff(ResourceLimit::keys(), [$except]));
    }

    private function passes(string $ruleSet, string $key, mixed $value): bool
    {
        $rules = $this->rules($ruleSet);

        return Validator::make([$key => $value], [$key => $rules[$key]])->passes();
    }

    /** @return array<string, mixed> */
    private function rules(string $ruleSet): array
    {
        return $ruleSet === 'create'
            ? NewProjectRules::rules('name', null, false)
            : (new UserUpdateRequest)->rules();
    }
}
