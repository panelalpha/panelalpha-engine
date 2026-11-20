<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\Paths;
use PHPUnit\Framework\TestCase;
use Tests\Support\PathsFixtures;

class PathsTest extends TestCase
{
    use PathsFixtures;

    /**
     * Every other reader of "which filenames count as the inner compose
     * file" goes through this list, in docker compose's own search order.
     */
    public function test_compose_file_candidates_are_the_docker_compose_v2_search_order(): void
    {
        $this->assertSame(
            ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'],
            Paths::composeFileCandidates()
        );
    }

    public function test_a_missing_file_is_not_the_engines_own(): void
    {
        $this->assertFalse(Paths::isEngineComposeFile('/nonexistent/'.uniqid('', true).'/docker-compose.yml'));
    }
}
