<?php

namespace Tests\Unit\System\Project\Dind\Strategy;

use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\DeployPlan;
use App\Lib\Deploy\Platform\DeployPlanContext;
use App\System\Project\Dind\Strategy\EntrypointWriter;
use Tests\TestCase;

/**
 * Pins what {@see EntrypointWriter::write()} writes, logs and answers for each kind of project.
 */
class EntrypointWriterWriteTest extends TestCase
{
    use StrategyHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHarness();
    }

    protected function tearDown(): void
    {
        app(DeployPlanContext::class)->clear();
        $this->tearDownHarness();
        parent::tearDown();
    }

    public function test_a_projects_own_entrypoint_is_copied_into_the_app_root(): void
    {
        $this->putFile('.panelalpha/entrypoint.sh', "#!/bin/sh\nexec ./serve\n");

        $wrote = $this->writer()->write(['app_root' => 'app'], $this->dir, '1001:1001');

        $this->record('answer', $wrote);
        $this->assertMatchesGolden('entrypoint-project-override');
    }

    public function test_an_empty_project_entrypoint_falls_back_to_the_generated_one(): void
    {
        $this->expressProject();
        $this->putFile('.panelalpha/entrypoint.sh', " \n");

        $wrote = $this->writer()->write(DetectProjectStrategy::detect($this->dir), $this->dir, '1001:1001');

        $this->record('answer', $wrote);
        $this->assertMatchesGolden('entrypoint-empty-override');
    }

    public function test_an_unresolved_platform_writes_nothing_and_names_the_ignored_stages(): void
    {
        app(DeployPlanContext::class)->set(DeployPlan::fromArray([
            'install' => [['id' => 'seed', 'run' => 'app seed']],
            'start' => [['run' => 'app serve', 'serve' => true]],
        ]));

        $wrote = $this->writer()->write(['platform' => 'no-such-platform'], $this->dir, null);

        $this->record('answer', $wrote);
        $this->assertMatchesGolden('entrypoint-unresolved-platform');
    }

    public function test_a_project_without_a_platform_writes_nothing_and_says_nothing(): void
    {
        $wrote = $this->writer()->write([], $this->dir, null);

        $this->record('answer', $wrote);
        $this->assertMatchesGolden('entrypoint-no-platform');
    }

    public function test_a_generated_entrypoint_carries_the_commands_the_start_and_the_setup_script(): void
    {
        $this->expressProject();
        $decision = DetectProjectStrategy::detect($this->dir);
        $decision['resolved_commands'] = ['npm-install' => 'npm ci', 'blank' => ' ', 7 => 'numeric id'];
        $decision['start_command'] = ' node server.js ';
        $decision['app_root'] = 'web';

        $wrote = $this->writer()->write(
            $decision,
            $this->dir,
            '1001:1001',
            ['install' => ['wait-for-db' => 'until nc -z db 3306; do sleep 1; done']]
        );

        $this->record('answer', $wrote);
        $this->assertMatchesGolden('entrypoint-generated-node');
    }

    public function test_a_php_platform_skips_the_projects_own_setup_script(): void
    {
        $this->putFile('composer.json', '{"require":{"php":"^8.3"},"scripts":{"setup":"php seed.php"}}');
        $this->putFile('index.php', "<?php echo 1;\n");
        $decision = DetectProjectStrategy::detect($this->dir);

        $wrote = $this->writer()->write($decision, $this->dir, null);

        $this->record('answer', $wrote);
        $this->assertMatchesGolden('entrypoint-generated-php');
    }

    public function test_an_app_config_manifest_whose_serve_command_is_not_its_own_writes_nothing(): void
    {
        $this->expressProject();
        $this->putFile('.panelalpha/panelalpha.yaml', <<<'YAML'
            id: acme-plain
            label: Acme
            runtime: node
            strategy: express
            port: 3000
            commands:
              - id: serve
                stage: start
                serve: true
                run: 'node server.js'
            YAML);
        $decision = DetectProjectStrategy::detect($this->dir);
        $appConfig = AppConfig::load(new LocalAppConfigSource, $this->dir);

        $wrote = $this->writer()->write($decision, $this->dir, null, [], $appConfig);

        $this->record('answer', [$decision['platform'], $wrote]);
        $this->assertMatchesGolden('entrypoint-app-config-without-serve');
    }

    private function writer(): EntrypointWriter
    {
        return new EntrypointWriter($this->harnessDind());
    }

    private function expressProject(): void
    {
        $this->putFile('package.json', '{"name":"x","dependencies":{"express":"^4"},"scripts":{"start":"node server.js","setup":"node seed.js"}}');
        $this->putFile('server.js', "require('express')().listen(3000)\n");
    }
}
