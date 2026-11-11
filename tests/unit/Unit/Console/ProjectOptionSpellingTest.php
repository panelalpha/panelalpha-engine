<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

/**
 * A command names its project with `--project` only; no second spelling of it is accepted.
 */
class ProjectOptionSpellingTest extends TestCase
{
    public function test_no_command_accepts_another_spelling_of_project(): void
    {
        $this->assertSame([], $this->commandsWithAProjectAlias());
    }

    public function test_commands_that_named_a_project_by_username_take_project(): void
    {
        foreach (['domain:delete', 'domain:create', 'project:settings:get', 'project:dind:recover', 'task:prune', 'deploy:log:prune', 'proxy:rule:list', 'project:rebuild'] as $name) {
            $this->assertTrue(Artisan::all()[$name]->getDefinition()->hasOption('project'), $name);
        }
    }

    /** @return list<string> */
    private function commandsWithAProjectAlias(): array
    {
        $found = [];
        foreach (Artisan::all() as $name => $command) {
            foreach (['username', 'user'] as $option) {
                if ($this->describesAProjectAlias($command, $option)) {
                    $found[] = "{$name} --{$option}";
                }
            }
        }

        return array_values(array_unique($found));
    }

    private function describesAProjectAlias(Command $command, string $option): bool
    {
        $definition = $command->getDefinition();

        return $definition->hasOption($option)
            && $definition->hasOption('project')
            && str_contains(strtolower($definition->getOption($option)->getDescription()), 'alias');
    }
}
