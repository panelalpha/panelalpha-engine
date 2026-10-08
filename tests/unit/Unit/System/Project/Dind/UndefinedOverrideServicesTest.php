<?php

namespace Tests\Unit\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A recipe override written for a service the engine stopped keeping (BookStack's
 * `node`) must not make compose refuse the whole project.
 */
class UndefinedOverrideServicesTest extends TestCase
{
    private string $tmpRoot;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/pa-override-' . bin2hex(random_bytes(4));
        $this->projectDir = $this->tmpRoot . '/home/alice/project';
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_the_override_loses_only_the_service_nothing_defines(): void
    {
        $dind = $this->dind();
        file_put_contents($dind->userAppComposeFilePath(), "services:\n  app:\n    image: project-app\n  db:\n    image: mysql:8\n");
        file_put_contents(
            $dind->userAppComposeOverridePath(),
            "services:\n  node:\n    entrypoint: [\"/bin/sh\", \"-c\", \"exit 0\"]\n    restart: \"no\"\n  db:\n    mem_limit: 256m\n"
        );

        $dind->strategy()->dropUndefinedOverrideServices(null);

        $override = Yaml::parse((string) file_get_contents($dind->userAppComposeOverridePath()));
        $this->assertSame(['db' => ['mem_limit' => '256m']], $override['services']);
    }

    public function test_an_override_with_nothing_to_drop_is_left_byte_for_byte(): void
    {
        $dind = $this->dind();
        file_put_contents($dind->userAppComposeFilePath(), "services:\n  app:\n    image: project-app\n");
        $raw = "# recipe comment\nservices:\n  app:\n    restart: \"no\"\n  cache:\n    image: redis:7\n";
        file_put_contents($dind->userAppComposeOverridePath(), $raw);

        $dind->strategy()->dropUndefinedOverrideServices(null);

        $this->assertSame($raw, file_get_contents($dind->userAppComposeOverridePath()));
        $this->assertSame(EngineArtifacts::RUN_COMPOSE_OVERRIDE, basename($dind->userAppComposeOverridePath()));
    }

    private function dind(): Dind
    {
        $model = new ModelsUser;
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000]);

        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->tmpRoot . '/home'), $model))->runtime();
        $this->assertInstanceOf(Dind::class, $runtime);

        return $runtime;
    }
}
