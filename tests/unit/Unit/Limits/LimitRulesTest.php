<?php

namespace Tests\Unit\Limits;

use App\Http\Requests\UserUpdateRequest;
use App\Lib\Host\HostMemory;
use App\Lib\Host\HostMemoryProbe;
use App\Lib\Limits\ResourceLimit;
use App\Lib\Project\NewProjectRules;
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
        $rules = $ruleSet === 'create'
            ? NewProjectRules::rules('name', null)
            : (new UserUpdateRequest())->rules();

        return Validator::make([$key => $value], [$key => $rules[$key]])->passes();
    }
}
