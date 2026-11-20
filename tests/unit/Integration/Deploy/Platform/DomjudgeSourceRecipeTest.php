<?php

namespace Tests\Integration\Deploy\Platform;

use App\Lib\Deploy\Platform\SourceRecipes;
use PHPUnit\Framework\TestCase;
use Tests\Support\DomjudgeSourceRecipeFixtures;

/**
 * DOMjudge's own compose is its contributor stack; the recipe runs the
 * published domserver with MariaDB and no judgehost.
 */
class DomjudgeSourceRecipeTest extends TestCase
{
    use DomjudgeSourceRecipeFixtures;

    public function test_the_database_passwords_are_generated_once(): void
    {
        $home = sys_get_temp_dir() . '/pa-domjudge-' . bin2hex(random_bytes(4));
        mkdir($home);
        $hook = SourceRecipes::directoryFor(self::URL) . '/hooks/prepare.sh';

        $seen = [];
        foreach ([1, 2] as $run) {
            exec('HOME=' . escapeshellarg($home) . ' bash ' . escapeshellarg($hook) . ' 2>&1', $output, $status);
            $this->assertSame(0, $status, implode("\n", $output));
            $seen[] = (string) file_get_contents($home . '/.panelalpha/domjudge/db.env');
        }
        exec('rm -rf ' . escapeshellarg($home));

        $this->assertMatchesRegularExpression('/^MYSQL_PASSWORD=[0-9a-f]{32}\nMYSQL_ROOT_PASSWORD=[0-9a-f]{32}$/m', $seen[0]);
        $this->assertSame($seen[0], $seen[1]);
    }
}
