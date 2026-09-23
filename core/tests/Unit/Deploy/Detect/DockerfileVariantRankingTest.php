<?php

namespace Tests\Unit\Deploy\Detect;

use App\Lib\Deploy\Detect\DockerfileFinder;
use PHPUnit\Framework\TestCase;

/**
 * Which Dockerfile gets built when a repository ships several.
 *
 * Variants used to be offered in directory order, so the winner was whatever
 * the filesystem happened to list first. Seven apps in the September support
 * batch built something the project never meant to run as its server --
 * Dockerfile.client instead of Dockerfile.server.production, Dockerfile.heroku
 * instead of docker/Dockerfile -- and the deploy reported a successful build
 * of the wrong image.
 */
class DockerfileVariantRankingTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dfrank-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /**
     * A fresh tree per call: two repo() calls in one test otherwise share a
     * directory, and the first call's docker/Dockerfile decides the second.
     *
     * @param list<string> $paths relative, directories created as needed
     */
    private function repo(array $paths, bool $withListing = true): ?string
    {
        $root = $this->dir . '/' . bin2hex(random_bytes(4));
        mkdir($root, 0o777, true);
        $files = [];
        foreach ($paths as $relative) {
            $full = $root . '/' . $relative;
            @mkdir(dirname($full), 0o777, true);
            // A usable Dockerfile needs a FROM and no COPY of a missing path.
            file_put_contents($full, "FROM nginx:alpine\nEXPOSE 8080\n");
            if (!str_contains($relative, '/')) {
                $files[strtolower($relative)] = true;
            }
        }

        return DockerfileFinder::find($root, $withListing ? $files : []);
    }

    /**
     * engine#258: PortsReport asks with no listing, and the ranking rewrite
     * only took the plain name from the listing -- so it found nothing and
     * inspect lost the Dockerfile's EXPOSE.
     */
    public function test_the_plain_dockerfile_is_found_without_a_listing(): void
    {
        $this->assertSame('Dockerfile', $this->repo(['Dockerfile'], false));
        $this->assertSame('Dockerfile', $this->repo(['Dockerfile', 'Dockerfile.prod', 'Dockerfile.dev'], false));
        $this->assertSame('Dockerfile.prod', $this->repo(['Dockerfile.prod', 'Dockerfile.dev'], false));
    }

    public function test_a_plain_dockerfile_still_wins_over_every_variant(): void
    {
        $this->assertSame(
            'Dockerfile',
            $this->repo(['Dockerfile', 'Dockerfile.prod', 'Dockerfile.dev'])
        );
    }

    /** yourspotify: Dockerfile.client was built instead of the server image. */
    public function test_a_production_variant_beats_a_client_variant(): void
    {
        $this->assertSame(
            'Dockerfile.server.production',
            $this->repo(['Dockerfile.client', 'Dockerfile.server.production'])
        );
    }

    /** canarytokens: Dockerfile.latest won over the one marked stable. */
    public function test_stable_beats_an_unmarked_variant(): void
    {
        $this->assertSame(
            'Dockerfile.stable',
            $this->repo(['Dockerfile.latest', 'Dockerfile.stable'])
        );
    }

    /**
     * manifest: Dockerfile.heroku won over docker/Dockerfile. A nested build
     * is a likelier production image than a root variant marked for a
     * development or platform-specific target.
     */
    public function test_a_nested_dockerfile_beats_a_demoted_root_variant(): void
    {
        $this->assertSame(
            'docker/Dockerfile',
            $this->repo(['Dockerfile.heroku', 'docker/Dockerfile'])
        );
        $this->assertSame(
            'build/Dockerfile',
            $this->repo(['Dockerfile.dev', 'build/Dockerfile'])
        );
    }

    /** Authelia: detection reached Dockerfile.coverage, an instrumented build. */
    public function test_a_coverage_variant_is_demoted(): void
    {
        $this->assertSame(
            'Dockerfile.web',
            $this->repo(['Dockerfile.coverage', 'Dockerfile.web'])
        );
    }

    /** A demoted variant is still built when it is all there is. */
    public function test_a_demoted_variant_is_used_when_it_is_the_only_one(): void
    {
        $this->assertSame('Dockerfile.dev', $this->repo(['Dockerfile.dev']));
    }

    /** An unmarked variant outranks a demoted one without needing a marker. */
    public function test_an_unmarked_variant_beats_a_demoted_one(): void
    {
        $this->assertSame('Dockerfile.web', $this->repo(['Dockerfile.test', 'Dockerfile.web']));
    }

    /**
     * Judged on every part of the suffix, and demotion wins the tie:
     * Dockerfile.prod.test is a test file whatever else it claims.
     */
    public function test_a_demoted_part_outweighs_a_preferred_part(): void
    {
        $this->assertSame(
            'Dockerfile.web',
            $this->repo(['Dockerfile.prod.test', 'Dockerfile.web'])
        );
    }

    /** Podman's spelling is still equivalent to a plain Dockerfile. */
    public function test_containerfile_is_taken_when_no_dockerfile_exists(): void
    {
        $this->assertSame('Containerfile', $this->repo(['Containerfile', 'Dockerfile.dev']));
    }

    public function test_nothing_to_find(): void
    {
        $this->assertNull($this->repo(['README.md']));
    }
}
