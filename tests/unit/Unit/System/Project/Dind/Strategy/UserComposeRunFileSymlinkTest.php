<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Models\Domain;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Paths;
use App\System\Project\Dind\Strategy\UserComposeStrategy;
use Tests\TestCase;
use Tests\Unit\System\Project\LocalHostSystem;

/**
 * A compose project's run file and its hardened override copy are written
 * as root into the account's own project directory, where the tenant can
 * put a symlink in their place. A rebuild must replace such a link, never
 * write or chown the file it points at.
 */
class UserComposeRunFileSymlinkTest extends TestCase
{
    private string $root;

    private string $projectDir;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->root = sys_get_temp_dir().'/pa-compose-link-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->root.'/home/alice/project';
        mkdir($this->projectDir, 0777, true);
        $this->outside = $this->root.'/victim.txt';
        file_put_contents($this->outside, 'victim');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->root));
        parent::tearDown();
    }

    public function test_a_hardened_run_file_replaces_a_symlink_instead_of_writing_through_it(): void
    {
        file_put_contents($this->projectDir.'/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        symlink($this->outside, $this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE);

        $this->refresh();

        $this->assertSame('victim', file_get_contents($this->outside));
        $this->assertReplacedWith(EngineArtifacts::RUN_COMPOSE, 'nginx:alpine');
    }

    public function test_a_run_file_copied_through_verbatim_replaces_a_symlink_too(): void
    {
        file_put_contents($this->projectDir.'/docker-compose.yml', "name: nothing-to-run\n");
        symlink($this->outside, $this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE);

        $this->refresh();

        $this->assertSame('victim', file_get_contents($this->outside));
        $this->assertReplacedWith(EngineArtifacts::RUN_COMPOSE, 'nothing-to-run');
    }

    public function test_the_hardened_override_copy_replaces_a_symlink_instead_of_writing_through_it(): void
    {
        file_put_contents($this->projectDir.'/docker-compose.yml', "services:\n  web:\n    image: nginx:alpine\n");
        file_put_contents($this->projectDir.'/'.Paths::CLIENT_OVERRIDE_FILENAME, "services:\n  web:\n    environment:\n      MODE: override\n");
        symlink($this->outside, $this->projectDir.'/'.EngineArtifacts::RUN_CLIENT_OVERRIDE);

        $this->refresh();

        $this->assertSame('victim', file_get_contents($this->outside));
        $this->assertReplacedWith(EngineArtifacts::RUN_CLIENT_OVERRIDE, 'override');
    }

    private function refresh(): void
    {
        (new UserComposeStrategy($this->dind()))->refreshRunFile($this->projectDir, '1000:1000');
    }

    private function assertReplacedWith(string $relative, string $expected): void
    {
        $path = $this->projectDir.'/'.$relative;
        $this->assertFalse(is_link($path), "{$relative} is still a symlink");
        $this->assertStringContainsString($expected, (string) file_get_contents($path));
    }

    private function dind(): DindProject
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }

            public function getMainDomain(): ?Domain
            {
                return null;
            }
        };
        $model->username = 'alice';
        $model->setDetails(['template' => 'dind', 'UID' => 1000, 'GID' => 1000, 'deploy_strategy' => 'compose']);
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->root, $this->root.'/home'), $model))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }
}
