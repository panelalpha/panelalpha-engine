<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\System\Project\Dind\Generation\ServingImages;
use PHPUnit\Framework\TestCase;

/**
 * A failed redeploy must not leave `project-app` pointing at the build it
 * rejected: what each running container runs is noted before the build.
 */
class ServingImagesTest extends TestCase
{
    public function test_each_running_container_is_noted_with_its_image_and_reference(): void
    {
        $inspect = "c0ffee sha256:2e3a4abc project-app\n"
            . "beef sha256:9f00 postgres:16\n"
            . "dead sha256:aa11 traefik/whoami@sha256:1234\n"
            . "garbage\n";

        $this->assertSame([
            ['id' => 'c0ffee', 'image' => 'sha256:2e3a4abc', 'ref' => 'project-app'],
            ['id' => 'beef', 'image' => 'sha256:9f00', 'ref' => 'postgres:16'],
        ], ServingImages::parse($inspect));
        $this->assertSame([], ServingImages::parse(''));
    }

    /** The old image is held by a tag of its own, or a build that takes its name lets it go. */
    public function test_the_holding_tag_is_named_after_the_image(): void
    {
        $this->assertSame('panelalpha-serving:2e3a4abc0123', ServingImages::keepTag('sha256:2e3a4abc0123456789'));
    }
}
