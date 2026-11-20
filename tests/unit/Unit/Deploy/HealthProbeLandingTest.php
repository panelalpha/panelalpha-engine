<?php

namespace Tests\Unit\Deploy;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;
use Tests\Support\HealthProbeLandingFixtures;

/**
 * The probe reports the path that answered, and follows a path-relative
 * Location to get there. Runs the generated script against a real
 * server, as HealthProbeRedirectTest does.
 */
class HealthProbeLandingTest extends TestCase
{
    use HealthProbeLandingFixtures;

    /** A probe line from before the path field still parses, as the root. */
    public function test_a_line_without_the_path_field_reads_as_the_root(): void
    {
        $line = "8000\thttp\t200 0.01\t\t" . base64_encode('hi');

        $this->assertSame('/', AppHealth::parseProbeOutput($line, [8000])[0]['path']);
    }
}
