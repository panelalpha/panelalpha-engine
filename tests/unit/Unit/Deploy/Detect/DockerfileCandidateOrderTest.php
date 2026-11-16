<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Detect\DockerfileFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The whole candidate order DockerfileFinder walks, not only the winner: the plain names,
 * then preferred, unmarked, nested and demoted variants, each in the order they were found.
 */
class DockerfileCandidateOrderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dforder-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * @param list<string> $onDisk
     * @param array<string, true> $listing
     * @return list<string>
     */
    private function candidates(array $onDisk, array $listing): array
    {
        foreach ($onDisk as $name) {
            file_put_contents($this->dir . '/' . $name, "FROM nginx:alpine\n");
        }
        $finder = new DockerfileFinder($this->dir, $listing);
        $method = new \ReflectionMethod($finder, 'candidates');

        return iterator_to_array($method->invoke($finder), false);
    }

    /**
     * @param list<string> $onDisk
     * @param array<string, true> $listing
     * @param list<string> $expected
     */
    #[DataProvider('trees')]
    public function test_the_candidate_order_is_pinned(array $onDisk, array $listing, array $expected): void
    {
        $this->assertSame($expected, $this->candidates($onDisk, $listing));
    }

    public static function trees(): array
    {
        $nested = ['docker/Dockerfile', 'scripts/docker/Dockerfile', 'build/Dockerfile'];

        return [
            'every kind, listed and on disk' => [
                [
                    'Dockerfile', 'Containerfile', 'Dockerfile.Prod', 'Dockerfile.server.production',
                    'Dockerfile.latest', 'Dockerfile.client', 'Dockerfile.dev', 'Dockerfile.prod.test',
                    'Containerfile.stable', 'Dockerfile.coverage', 'README.md',
                ],
                [
                    'dockerfile' => true, 'dockerfile.zeta' => true, 'dockerfile.prod' => true,
                    'dockerfile.heroku' => true, 'readme.md' => true,
                ],
                [
                    'Dockerfile', 'Containerfile',
                    'dockerfile.prod', 'Containerfile.stable', 'Dockerfile.Prod', 'Dockerfile.server.production',
                    'dockerfile.zeta', 'Dockerfile.latest',
                    ...$nested,
                    'dockerfile.heroku', 'Dockerfile.client', 'Dockerfile.coverage', 'Dockerfile.dev', 'Dockerfile.prod.test',
                ],
            ],
            'no listing, plain name on disk' => [
                ['Dockerfile', 'Dockerfile.release'],
                [],
                ['Dockerfile', 'Dockerfile.release', ...$nested],
            ],
            'listing only, nothing on disk' => [
                [],
                ['dockerfile' => true, 'dockerfile.ci' => true],
                ['Dockerfile', ...$nested, 'dockerfile.ci'],
            ],
            'nothing at all' => [[], [], $nested],
            'a dot with no suffix' => [
                ['Dockerfile.'],
                ['dockerfile.' => true],
                ['dockerfile.', ...$nested],
            ],
        ];
    }
}
