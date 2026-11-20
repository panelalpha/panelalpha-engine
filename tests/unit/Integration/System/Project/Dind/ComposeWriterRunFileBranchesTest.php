<?php

namespace Tests\Integration\System\Project\Dind;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\Detect\PlaceholderPage;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\Dockerfile\NginxConfig;
use App\Lib\Project\ApiAccess;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Dind as DindProject;
use Symfony\Component\Yaml\Yaml;
use Tests\Integration\System\Project\LocalHostSystem;
use Tests\TestCase;

/**
 * The run file edits {@see \App\System\Project\Dind\ComposeWriter} refuses, the image swap it declines, and the
 * placeholder pages and nginx config it writes.
 */
class ComposeWriterRunFileBranchesTest extends TestCase
{
    private string $tmpRoot;

    private string $projectDir;

    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->app->forgetInstance('encrypter');
        $this->username = 'cwb'.bin2hex(random_bytes(3));
        $username = $this->username;
        $this->beforeApplicationDestroyed(static fn () => DeployLogger::deleteUserLogs($username));
        $this->tmpRoot = sys_get_temp_dir().'/pa-compose-writer-'.bin2hex(random_bytes(4));
        $this->projectDir = $this->tmpRoot.'/home/'.$this->username.'/project';
        mkdir($this->projectDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmpRoot));
        parent::tearDown();
    }

    public function test_no_run_file_means_nothing_to_merge(): void
    {
        $this->assertFalse($this->dind()->composeWriter()->mergeAccountEnv(['A' => '1'], []));
        $this->assertFileDoesNotExist($this->runFilePath());
    }

    public function test_api_access_is_not_written_into_a_generated_file_without_an_app_service(): void
    {
        $path = $this->writeRunFile("services:\n  web:\n    image: nginx\n    labels:\n      ".GeneratedCompose::LABEL.": framework\n  app: null\n");
        $before = (string) file_get_contents($path);

        $this->assertFalse($this->dind()->composeWriter()->mergeApiAccessEnv([ApiAccess::ENV_URL => 'https://engine.example.test']));
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_api_access_is_not_written_into_a_list_environment(): void
    {
        $path = $this->writeRunFile($this->generated(['A=1']));
        $before = (string) file_get_contents($path);

        $this->assertFalse($this->dind()->composeWriter()->mergeApiAccessEnv([ApiAccess::ENV_URL => 'https://engine.example.test']));
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_api_access_that_is_already_absent_leaves_the_file_alone(): void
    {
        $path = $this->writeRunFile($this->generated(['APP_ENV' => 'production']));
        $before = (string) file_get_contents($path);

        $this->assertFalse($this->dind()->composeWriter()->mergeApiAccessEnv([]));
        $this->assertSame($before, file_get_contents($path));
    }

    public function test_api_access_is_set_and_then_taken_out_again(): void
    {
        $path = $this->writeRunFile($this->generated(['APP_ENV' => 'production']));
        $writer = $this->dind()->composeWriter();

        $this->assertTrue($writer->mergeApiAccessEnv([ApiAccess::ENV_URL => 'https://engine.example.test']));
        $this->assertSame('https://engine.example.test', $this->environment($path)[ApiAccess::ENV_URL]);

        $this->assertTrue($writer->mergeApiAccessEnv([]));
        $this->assertSame(['APP_ENV' => 'production'], $this->environment($path));
    }

    public function test_an_app_image_other_than_the_one_named_is_not_replaced(): void
    {
        $path = $this->writeRunFile($this->generated([], 'node:22'));

        $this->assertFalse($this->dind()->composeWriter()->replaceAppImage('node:20', 'node:24', '1000:1000'));
        $this->assertSame('node:22', Yaml::parseFile($path)['services']['app']['image']);
    }

    public function test_an_image_written_where_the_line_cannot_be_rewritten_is_not_replaced(): void
    {
        $path = $this->writeRunFile("services:\n  app: { image: node:22 }\n");

        $this->assertFalse($this->dind()->composeWriter()->replaceAppImage('node:22', 'node:24', '1000:1000'));
        $this->assertSame('node:22', Yaml::parseFile($path)['services']['app']['image']);
    }

    public function test_the_placeholder_nginx_config_is_written_beside_the_project(): void
    {
        $this->dind()->composeWriter()->writePlaceholderNginxConf($this->projectDir, '1000:1000');

        $this->assertSame(NginxConfig::placeholder(), file_get_contents($this->projectDir.'/'.NginxConfig::FILENAME));
    }

    public function test_the_welcome_page_says_the_environment_is_ready(): void
    {
        $this->dind()->composeWriter()->writeWelcomeIndexHtml($this->projectDir);

        $page = (string) file_get_contents($this->projectDir.'/index.html');
        $this->assertStringContainsString('<title>'.PlaceholderPage::WELCOME_TITLE.'</title>', $page);
        $this->assertStringContainsString('Your environment is ready', $page);
        $this->assertTrue(PlaceholderPage::isOneOf($this->projectDir.'/index.html'));
    }

    public function test_a_generated_file_that_builds_its_app_freezes_no_image(): void
    {
        $model = $this->model();

        $this->dindFor($model)->composeWriter()->writeGeneratedCompose(
            $this->projectDir,
            "services:\n  app:\n    build: .\n    labels:\n      ".GeneratedCompose::LABEL.": framework\n",
            '1000:1000'
        );

        $this->assertSame('.', Yaml::parseFile($this->runFilePath())['services']['app']['build']);
        $this->assertArrayNotHasKey('deploy_image', $model->getDetails());
    }

    public function test_a_declared_public_port_is_published_on_the_app_service(): void
    {
        $model = $this->model([ProjectPublicRouting::DETAIL => ['declared' => [
            'ports' => [['listen' => 25565, 'to' => 25565, 'transport' => 'tcp']],
        ]]]);
        $logger = DeployLogger::start($this->username);

        $this->dindFor($model)->composeWriter()->writeGeneratedCompose($this->projectDir, $this->generated([], 'itzg/minecraft-server'), '1000:1000');

        $this->assertContains('Published tcp 25565 on app for public_ports', array_column($logger->entries(), 'msg'));
        $this->assertSame(['25565:25565'], Yaml::parseFile($this->runFilePath())['services']['app']['ports']);
    }

    private function dind(): DindProject
    {
        return $this->dindFor($this->model());
    }

    private function dindFor(ModelsUser $model): DindProject
    {
        $runtime = (new ProjectAggregate(new LocalHostSystem($this->tmpRoot, $this->tmpRoot.'/home'), $model))->runtime();
        $this->assertInstanceOf(DindProject::class, $runtime);

        return $runtime;
    }

    /** @param array<string, mixed> $details */
    private function model(array $details = []): ModelsUser
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $model->username = $this->username;
        $model->domain = $this->username.'.example.test';
        $model->setDetails(array_merge(['template' => 'dind', 'UID' => 1000, 'GID' => 1000], $details));

        return $model;
    }

    /** @param array<mixed> $environment */
    private function generated(array $environment, string $image = 'nginx:alpine'): string
    {
        $app = ['image' => $image, 'labels' => [GeneratedCompose::LABEL => 'framework']];
        if ($environment !== []) {
            $app['environment'] = $environment;
        }

        return Yaml::dump(['services' => ['app' => $app]], 6, 2);
    }

    private function runFilePath(): string
    {
        return $this->dind()->userAppComposeFilePath();
    }

    private function writeRunFile(string $yaml): string
    {
        $path = $this->runFilePath();
        file_put_contents($path, $yaml);

        return $path;
    }

    /** @return array<string, mixed> */
    private function environment(string $path): array
    {
        return (array) (Yaml::parseFile($path)['services']['app']['environment'] ?? []);
    }
}
