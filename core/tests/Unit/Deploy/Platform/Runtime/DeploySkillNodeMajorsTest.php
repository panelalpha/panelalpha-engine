<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * engine#57: an agent told users the engine supports only Node 22. The deploy
 * skill names the majors and the default, so it has to name the real ones.
 */
class DeploySkillNodeMajorsTest extends TestCase
{
    private const SKILL = __DIR__ . '/../../../../../../.claude/skills/deploy/SKILL.md';

    public function test_the_deploy_skill_names_the_node_majors_the_engine_offers(): void
    {
        $skill = (string) file_get_contents(self::SKILL);
        $majors = NodeRuntime::majors();
        $last = array_pop($majors);

        $this->assertStringContainsString(
            'Node majors are ' . implode(', ', $majors) . ' and ' . $last . ';',
            $skill
        );
        $this->assertStringContainsString('naming none gets ' . NodeRuntime::defaultMajor() . ',', $skill);
    }
}
