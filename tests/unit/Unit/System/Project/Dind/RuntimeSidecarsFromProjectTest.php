<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\ProjectFiles;
use App\System\Project\Dind\RuntimeSidecars;
use PHPUnit\Framework\TestCase;
use Tests\Support\RuntimeSidecarsFromProjectFixtures;

/**
 * Ticket 04: a recipe repo (framework/Dockerfile/Railpack strategy, not the
 * compose strategy) that ships its own local-dev compose file -- a Postgres
 * sidecar beside an `app` service built from the repo's own root, Sail-
 * or Symfony-Docker-shaped.
 *
 * {@see RuntimeSidecars::runtimeSidecarsFromProject()} only ever *reads*
 * that file through {@see ProjectFiles::read()} to harvest its backing
 * services; nothing in this class writes anything, which is what makes the
 * three promises here (no stray stash file, the sidecar survives, the
 * client's own compose file is untouched) hold simultaneously -- proven here
 * by driving the real method rather than asserting the absence of a write
 * by reading the source.
 */
class RuntimeSidecarsFromProjectTest extends TestCase
{
    use RuntimeSidecarsFromProjectFixtures;

    private const PROJECT_DIR = '/home/acme/project';

    public function test_a_recipes_local_dev_sidecar_is_kept_with_no_stash_file_and_the_client_file_untouched(): void
    {
        $clientPath = self::PROJECT_DIR . '/docker-compose.yml';
        $clientYaml = <<<'YAML'
        services:
          app:
            build: .
            volumes:
              - .:/var/www/html
            ports:
              - "8000:8000"
          db:
            image: postgres:16
            environment:
              POSTGRES_USER: acme
              POSTGRES_PASSWORD: secret
              POSTGRES_DB: acme
            volumes:
              - dbdata:/var/lib/postgresql/data
        volumes:
          dbdata:
        YAML;

        $system = $this->stubbedSystem([$clientPath => $clientYaml]);
        $dind = $this->stubbedDind($system);

        $result = (new RuntimeSidecars($dind))->runtimeSidecarsFromProject(self::PROJECT_DIR);

        // The sidecar is kept...
        $this->assertSame(['db'], array_keys($result['services']), 'the Postgres sidecar must survive extraction');

        // ...nothing was ever written back, so no `*.panelalpha-local` stash
        // file (the previous engine's mechanism) and no write to the client's
        // own file either -- this class only reads.
        $this->assertSame([], $this->copiedTo, 'reading a project for its sidecars must never write anything');
        $this->assertSame(
            $clientYaml,
            $system->filesystem()->fileGetContents($clientPath),
            "the client's own compose file must stay exactly what it shipped"
        );
    }

    /**
     * The production file says what runs beside the app; the workstation file
     * added caddy, a second database and a debug server.
     */
    public function test_a_harvested_build_image_and_app_mounts_reach_the_decision(): void
    {
        $decision = (new RuntimeSidecars($this->stubbedDind($this->stubbedSystem([]))))->mergeRuntimeSidecars(
            ['env' => ['A' => '1']],
            ['services' => [], 'volumes' => [], 'env' => [], 'build_image' => 'node:22-bookworm', 'app_mounts' => ['./uploads:/app/uploads']]
        );

        $this->assertSame('node:22-bookworm', $decision['build_image']);
        $this->assertSame(['./uploads:/app/uploads'], $decision['app_mounts']);
        $this->assertSame(['A' => '1'], $decision['env']);
        $this->assertArrayNotHasKey('sidecars', $decision);
    }
}
