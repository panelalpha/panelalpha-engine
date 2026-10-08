<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Git\GitStatusCommand;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Input\ArrayInput;
use Tests\TestCase;

class GitStatusCommandTest extends TestCase
{
    public function test_resolve_path_defaults_to_null(): void
    {
        $command = $this->bindCommand(new ArrayInput(['username' => 'alice']));
        $method = new ReflectionMethod(GitStatusCommand::class, 'resolvePath');

        $this->assertNull($method->invoke($command));
    }

    public function test_resolve_path_honours_explicit_path(): void
    {
        $command = $this->bindCommand(new ArrayInput([
            'username' => 'alice',
            '--path' => 'public_html',
        ]));
        $method = new ReflectionMethod(GitStatusCommand::class, 'resolvePath');

        $this->assertSame('public_html', $method->invoke($command));
    }

    private function bindCommand(ArrayInput $input): GitStatusCommand
    {
        $command = $this->app->make(GitStatusCommand::class);
        $command->setLaravel($this->app);
        $command->mergeApplicationDefinition();
        $input->bind($command->getDefinition());

        $inputProp = (new ReflectionClass($command))->getProperty('input');
        $inputProp->setValue($command, $input);

        return $command;
    }
}
