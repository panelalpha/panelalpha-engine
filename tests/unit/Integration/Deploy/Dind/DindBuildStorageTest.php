<?php

namespace Tests\Integration\Deploy\Dind;

use App\Lib\Deploy\Dind\DindBuildStorage;
use PHPUnit\Framework\TestCase;

class DindBuildStorageTest extends TestCase
{
    /**
     * StorageReclaim runs the wipe script with `sh -lc`, which is dash in the
     * account image: a bash array failed every heavy build with
     * `sh: 3: Syntax error: "(" unexpected`.
     */
    public function test_the_wipe_script_parses_under_posix_sh(): void
    {
        foreach ([DindBuildStorage::fullWipeScript()] as $script) {
            $process = proc_open(['sh', '-n'], [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes);
            fwrite($pipes[0], $script);
            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $this->assertSame(0, proc_close($process), $stderr);
        }
    }
}
