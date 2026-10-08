<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Detect\DockerfileFinder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A Dockerfile's `VOLUME` became a volume nobody owned.
 *
 * With no volume named in the compose file, Docker creates an *anonymous*
 * one per declared path: not under `~/project`, not backed up, orphaned the
 * moment the container is recreated. Every Dockerfile application's data has
 * been living somewhere the engine could neither see nor keep.
 *
 * Vaultwarden is what made it visible rather than merely true. It reads
 * /proc/self/mountinfo, matches its data folder against
 * `/volumes/[a-z0-9]{64}/_data` -- the anonymous form -- and exits 1:
 *
 *     [ERROR] No persistent volume!
 *     # It looks like you did not configure a persistent volume!
 *
 * every time, forever, after a 12-minute Rust build.
 *
 * Named rather than a bind mount on purpose: a bind mount hides whatever the
 * image ships at that path, while a volume copies it out on first use, which
 * is the semantics an image declaring VOLUME was written against.
 */
class DockerfileVolumesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function compose(array $decision): array
    {
        return Yaml::parse(DeployCompose::dockerfile('Dockerfile', 80, $decision));
    }

    public function test_a_declared_volume_is_named_and_mounted(): void
    {
        $compose = $this->compose(['dockerfile_volumes' => ['/data']]);

        $this->assertSame(['data-data:/data'], $compose['services']['app']['volumes']);
        $this->assertArrayHasKey('data-data', $compose['volumes']);
    }

    /** Several paths, each with its own volume, in declaration order. */
    public function test_every_declared_path_gets_one(): void
    {
        $compose = $this->compose(['dockerfile_volumes' => ['/data', '/config']]);

        $this->assertSame(
            ['data-data:/data', 'data-config:/config'],
            $compose['services']['app']['volumes']
        );
        $this->assertSame(['data-data', 'data-config'], array_keys($compose['volumes']));
    }

    /** A name has to survive a redeploy, or the data does not. */
    public function test_the_name_is_derived_from_the_path_and_stable(): void
    {
        $first = $this->compose(['dockerfile_volumes' => ['/var/lib/grafana']]);
        $second = $this->compose(['dockerfile_volumes' => ['/var/lib/grafana']]);

        $this->assertSame(
            ['data-var-lib-grafana:/var/lib/grafana'],
            $first['services']['app']['volumes']
        );
        $this->assertSame($first, $second);
    }

    /** A project that mounts the path itself keeps its own choice. */
    public function test_an_existing_mount_is_not_overridden(): void
    {
        $compose = $this->compose([
            'dockerfile_volumes' => ['/data'],
            'volumes' => [],
        ]);

        $this->assertContains('data-data:/data', $compose['services']['app']['volumes']);
    }

    /** No VOLUME, no change — the file stays exactly as it was. */
    public function test_a_dockerfile_without_volumes_is_untouched(): void
    {
        $compose = $this->compose(['env' => ['URL' => 'https://x.test']]);

        $this->assertArrayNotHasKey('volumes', $compose['services']['app']);
        $this->assertArrayNotHasKey('volumes', $compose);
    }

    // --- the parser ---

    public function test_it_reads_the_shell_form(): void
    {
        $this->assertSame(['/data'], DockerfileFinder::declaredVolumesIn("FROM x\nVOLUME /data\n"));
    }

    public function test_it_reads_the_json_form(): void
    {
        $this->assertSame(
            ['/data', '/config'],
            DockerfileFinder::declaredVolumesIn('VOLUME ["/data", "/config"]' . "\n")
        );
    }

    public function test_it_reads_several_paths_on_one_line(): void
    {
        $this->assertSame(['/a', '/b'], DockerfileFinder::declaredVolumesIn("VOLUME /a /b\n"));
    }

    /** A build-arg path is not something to guess at. */
    public function test_a_variable_path_is_skipped(): void
    {
        $this->assertSame([], DockerfileFinder::declaredVolumesIn('VOLUME $DATA_DIR' . "\n"));
    }

    /** A relative path is not a container path. */
    public function test_a_relative_path_is_skipped(): void
    {
        $this->assertSame([], DockerfileFinder::declaredVolumesIn("VOLUME data\n"));
    }

    public function test_a_dockerfile_with_no_volume_line_answers_empty(): void
    {
        $this->assertSame([], DockerfileFinder::declaredVolumesIn("FROM x\nCMD [\"sh\"]\n"));
    }
}
