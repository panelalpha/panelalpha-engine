<?php

namespace Tests\Unit\Console;

use App\Console\Commands\Wp\SelectsWordPressInstall;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

/** Every wordpress:* command takes the project, --path and --service from one place. */
class WordPressCommandOptionsTest extends TestCase
{
    public function test_every_wordpress_command_selects_the_install_the_same_way(): void
    {
        $checked = 0;
        foreach (glob(dirname(__DIR__, 4).'/core/app/Console/Commands/Wp/*.php') as $file) {
            $class = 'App\\Console\\Commands\\Wp\\'.basename($file, '.php');
            $reflection = new \ReflectionClass($class);
            if (!$reflection->isSubclassOf(Command::class) || $reflection->isAbstract()) {
                continue;
            }

            $this->assertContains(SelectsWordPressInstall::class, self::traitsOf($reflection), $class);
            $definition = $reflection->newInstance()->getDefinition();
            $this->assertSame('username', array_key_first($definition->getArguments()), $class.': the project comes first');
            $this->assertTrue($definition->getArgument('username')->isRequired(), $class);
            $this->assertTrue($definition->hasOption('path'), $class);
            $this->assertTrue($definition->hasOption('service'), $class);
            $this->assertTrue($definition->getOption('service')->isValueRequired(), $class);
            $checked++;
        }

        $this->assertGreaterThanOrEqual(42, $checked);
    }

    /** @return list<string> */
    private static function traitsOf(\ReflectionClass $class): array
    {
        $traits = [];
        for ($c = $class; $c !== false; $c = $c->getParentClass()) {
            $traits = [...$traits, ...$c->getTraitNames()];
        }

        return $traits;
    }
}
