<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\EnvFile;
use App\Models\User;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind;
use Tests\Integration\System\Project\LocalHostSystem;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * What {@see Dind\ProjectEnvironment::apply()} makes of an env template the compose file or the account's
 * address answers in part, of a nested compose file's `.env`, and of files that are not what they seem.
 */
class ProjectEnvironmentTemplateBranchesTest extends TestCase
{
    use InMemoryDatabase;

    private string $tmpRoot;

    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->tmpRoot = sys_get_temp_dir().'/pa-env-branches-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->tmpRoot.'/home/envbr/project';
        mkdir($this->projectDir, 0777, true);
        mkdir($this->tmpRoot.'/users/envbr', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpRoot));
        DeployLogger::deleteUserLogs('envbr');
        parent::tearDown();
    }

    public function test_a_template_with_nul_bytes_is_ignored(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "A=1\0\0B=2\n");

        $lines = $this->applied();

        $this->assertContains('Ignored .env.example: it is not a text file Docker Compose can read (NUL bytes in an unrecognised encoding)', $lines);
        $this->assertContains('Using default environment variables (source: none)', $lines);
        $this->assertFileDoesNotExist($this->projectDir.'/.env.default');
    }

    public function test_published_secrets_blanks_and_public_url_keys_of_the_template_are_handled(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "SECRET_KEY=changeme\nAPI_TOKEN=<your-api-token>\nAPP_URL=\n");

        $lines = $this->applied(withDomain: true);

        $this->assertContains('Replaced the published placeholder in SECRET_KEY from .env.example with a generated secret', $lines);
        $this->assertContains('Left to the image, which sets them: .env.example had blanks to fill in for API_TOKEN', $lines);
        $this->assertContains("Filled the blank public-URL keys in .env.example with this account's address: APP_URL", $lines);
        $env = $this->vars('.env');
        $this->assertSame('https://envbr.test', $env['APP_URL']);
        $this->assertNotSame('changeme', $env['SECRET_KEY']);
        $this->assertArrayNotHasKey('API_TOKEN', $env);
    }

    public function test_a_blank_public_url_key_without_an_address_is_left_blank(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "APP_URL=\n");

        $lines = $this->applied();

        $this->assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'Filled the blank public-URL keys'))));
        $this->assertSame('', $this->vars('.env')['APP_URL']);
    }

    public function test_a_required_own_url_of_the_compose_file_is_pointed_at_the_public_address(): void
    {
        file_put_contents($this->projectDir.'/docker-compose.yml', "services:\n  app:\n    image: acme/app\n    environment:\n      SITE_URL: \${SITE_URL:?set it}\n");

        $lines = $this->applied(['deploy_strategy' => 'compose'], withDomain: true);

        $this->assertContains('Pointed the application address at its public URL: SITE_URL', $lines);
        $this->assertSame('https://envbr.test', $this->vars('.env')['SITE_URL']);
    }

    public function test_template_keys_the_compose_file_defaults_are_left_to_it(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "FOO=from-template\nBAR=kept\n");
        file_put_contents($this->projectDir.'/docker-compose.yml', "services:\n  app:\n    image: acme/app\n    environment:\n      FOO: \${FOO:-bar}\n");

        $lines = $this->applied(['deploy_strategy' => 'compose']);

        $this->assertContains('Left to docker-compose.yml, which defaults them: FOO', $lines);
        $env = $this->vars('.env');
        $this->assertArrayNotHasKey('FOO', $env);
        $this->assertSame('kept', $env['BAR']);
    }

    public function test_a_compose_project_without_a_compose_file_keeps_its_template_whole(): void
    {
        file_put_contents($this->projectDir.'/.env.example', "FOO=from-template\n");

        $this->applied(['deploy_strategy' => 'compose']);

        $this->assertSame('from-template', $this->vars('.env')['FOO']);
    }

    public function test_a_nested_compose_file_without_its_own_env_changes_nothing_at_the_root(): void
    {
        mkdir($this->projectDir.'/docker');
        $compose = $this->projectDir.'/docker/docker-compose.yml';
        file_put_contents($compose, "services:\n  api:\n    image: acme/api\n");
        file_put_contents($this->projectDir.'/.env', "MODE=root\n");

        $lines = $this->applied(['deploy_strategy' => 'compose'], composePath: $compose);

        $this->assertSame("MODE=root\n", file_get_contents($this->projectDir.'/.env'));
        $this->assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'Compose reads docker/.env'))));
    }

    public function test_a_nested_env_with_nothing_the_root_lacks_is_not_carried(): void
    {
        mkdir($this->projectDir.'/docker');
        $compose = $this->projectDir.'/docker/docker-compose.yml';
        file_put_contents($compose, "services:\n  api:\n    image: acme/api\n");
        file_put_contents($this->projectDir.'/docker/.env', "MODE=nested\n");
        file_put_contents($this->projectDir.'/.env', "MODE=root\n");

        $lines = $this->applied(['deploy_strategy' => 'compose'], composePath: $compose);

        $this->assertSame("MODE=root\n", file_get_contents($this->projectDir.'/.env'));
        $this->assertSame([], array_values(array_filter($lines, static fn (string $line): bool => str_contains($line, 'Compose reads docker/.env'))));
    }

    public function test_a_run_file_that_is_not_yaml_is_left_alone(): void
    {
        $runFile = $this->projectDir.'/'.EngineArtifacts::RUN_COMPOSE;
        file_put_contents($runFile, "just some text\n");
        file_put_contents($this->projectDir.'/.env.example', "A=1\n");

        $this->applied();

        $this->assertSame("just some text\n", file_get_contents($runFile));
        $this->assertSame('1', $this->vars('.env')['A']);
    }

    public function test_a_directory_where_a_nested_env_belongs_is_replaced_by_the_copy(): void
    {
        mkdir($this->projectDir.'/backend/.env', 0777, true);
        file_put_contents($this->projectDir.'/backend/.env/stray', 'x');
        file_put_contents($this->projectDir.'/backend/.env.example', "DEBUG=1\n");

        $this->applied();

        $this->assertFileIsReadable($this->projectDir.'/backend/.env');
        $this->assertFalse(is_dir($this->projectDir.'/backend/.env'));
        $this->assertSame('1', $this->vars('backend/.env')['DEBUG']);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<string>
     */
    private function applied(array $details = [], bool $withDomain = false, ?string $composePath = null): array
    {
        $model = $this->makeUser('envbr', array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));
        if ($withDomain) {
            $this->makeMainDomain($model, 'envbr.test');
        }
        $dind = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->tmpRoot.'/home'), User::query()->findOrFail($model->id)))->runtime();
        $this->assertInstanceOf(Dind::class, $dind);

        $logger = DeployLogger::start('envbr');
        try {
            (new class($dind) extends Dind\ProjectEnvironment
            {
                protected function envIsTracked(): bool
                {
                    return false;
                }
            })->apply($composePath);

            return array_values(array_column($logger->read()['lines'], 'msg'));
        } finally {
            $logger->finish(DeployLogger::STATUS_SUCCESS);
        }
    }

    /** @return array<string, string> */
    private function vars(string $relative): array
    {
        $values = [];
        foreach (EnvFile::parse((string) file_get_contents($this->projectDir.'/'.$relative)) as $row) {
            if (($row['type'] ?? '') === 'variable') {
                $values[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        }

        return $values;
    }
}
