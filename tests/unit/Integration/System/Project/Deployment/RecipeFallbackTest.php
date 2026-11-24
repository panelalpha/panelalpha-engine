<?php

namespace Tests\Integration\System\Project\Deployment;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\RecipeChoiceContext;
use App\Lib\Deploy\Platform\Runtime\JsPackageManager;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System\Project as ProjectAggregate;
use App\System\Project\Deployment\RecipeAttempt;
use App\System\Project\Deployment\RecipeFallback;
use App\System\Project\Dind;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use App\System\Project\Dind\ShellOperations;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * A failed start goes on to the next recipe that claims the project, by priority, against the shipped manifests:
 * a checkout with composer.json and an Express package.json is claimed by PHP, then Express, then Node.
 */
class RecipeFallbackTest extends TestCase
{
    private string $dir = '';

    private ModelsUser $model;

    private ProjectAggregate $aggregate;

    /** @var list<?string> the recipe each start ran with */
    private array $started = [];

    private int $prepared = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app(RecipeChoiceContext::class)->clear();
        $this->dir = sys_get_temp_dir() . '/pa-recipe-fallback-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir . '/composer.json', '{"require":{"php":">=8.1","monolog/monolog":"^3"}}');
        file_put_contents($this->dir . '/index.php', '<?php echo "hi";');
        file_put_contents($this->dir . '/package.json', '{"scripts":{"start":"node server.js"},"dependencies":{"express":"^4"}}');
        file_put_contents($this->dir . '/server.js', 'require("express")().listen(3000)');
        $this->model = $this->model(['deploy_platform' => 'php']);
    }

    protected function tearDown(): void
    {
        app(RecipeChoiceContext::class)->clear();
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_the_fixture_is_claimed_by_php_then_by_node_recipes(): void
    {
        $order = PlatformCandidates::fallbackOrder(ProjectContext::at($this->dir));

        $this->assertSame(['php', 'express', 'node'], $order);
    }

    public function test_a_start_that_works_tries_nothing_else(): void
    {
        $this->expectCleanup()->expects($this->never())->method('abortRunningDeploy');

        $result = $this->fallback()->start($this->starts([$this->ok()]), $this->prepare());

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame([null], $this->started);
        $this->assertSame(0, $this->prepared);
        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_RECIPE] ?? null);
    }

    public function test_a_failed_start_falls_back_to_the_next_recipe_and_remembers_it(): void
    {
        $this->expectCleanup()->expects($this->once())->method('abortRunningDeploy')->with(false);

        $result = $this->fallback()->start(
            $this->starts([$this->failed('composer: Your requirements could not be resolved'), $this->ok()]),
            $this->prepare()
        );

        $this->assertSame([null, 'express'], $this->started);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(1, $this->prepared);
        $this->assertSame('express', $this->model->getDetails()[RecipeFallback::DETAIL_RECIPE]);
    }

    /** Monitoring learns what failed before the start, which the report's strategy alone would hide. */
    public function test_the_attempts_are_kept_for_the_deploy_report(): void
    {
        $this->fallback()->start(
            $this->starts([$this->failed('Problem 1 - Root composer.json requires acme/missing, it could not be found in any version'), $this->ok()]),
            $this->prepare()
        );

        $this->assertSame([
            'remembered' => false,
            'attempts' => [
                ['recipe' => 'php', 'package_manager' => null, 'failed' => true, 'rule' => 'composer-package-not-found'],
                ['recipe' => 'express', 'package_manager' => 'npm', 'failed' => false, 'rule' => null],
            ],
            'started' => ['recipe' => 'express', 'package_manager' => 'npm'],
        ], $this->model->getDetails()[RecipeFallback::DETAIL_REPORT]);
    }

    /** A deploy that started with what it remembered is a fallback too, though it made one attempt. */
    public function test_a_start_with_a_remembered_choice_is_reported(): void
    {
        app(RecipeChoiceContext::class)->fallTo('express');
        $this->model->setDetails(['deploy_platform' => 'express', RecipeFallback::DETAIL_DEPLOYED_PACKAGE_MANAGER => 'npm']);

        $this->fallback()->start($this->starts([$this->ok()]), $this->prepare());

        $report = $this->model->getDetails()[RecipeFallback::DETAIL_REPORT];
        $this->assertTrue($report['remembered']);
        $this->assertCount(1, $report['attempts']);
        $this->assertSame(['recipe' => 'express', 'package_manager' => 'npm'], $report['started']);
    }

    public function test_a_deploy_that_tried_only_what_detection_picked_reports_nothing(): void
    {
        $this->fallback()->start($this->starts([$this->ok()]), $this->prepare());

        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_REPORT] ?? null);
    }

    public function test_a_start_that_throws_falls_back_too(): void
    {
        $result = $this->fallback()->start(
            $this->starts([new \RuntimeException('Failed to compile on host'), $this->ok()]),
            $this->prepare()
        );

        $this->assertSame([null, 'express'], $this->started);
        $this->assertSame(0, $result['exit_code']);
    }

    /**
     * Every candidate failed: the first recipe's failure is reported, and the project is prepared for that recipe
     * again, so what it records and what is on disk agree with the failure it shows.
     */
    public function test_when_no_recipe_starts_the_first_failure_is_reported(): void
    {
        $candidates = count(PlatformCandidates::fallbackOrder(ProjectContext::at($this->dir)));
        $failures = array_map(fn (int $i) => $this->failed("recipe {$i} failed"), range(1, $candidates));

        $result = $this->fallback()->start($this->starts($failures), $this->prepare());

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('recipe 1 failed', $result['stderr']);
        $this->assertCount($candidates, $this->started);
        $this->assertSame($candidates, $this->prepared, 'one prepare per later recipe, and one back to the first');
        $this->assertNull(app(RecipeChoiceContext::class)->get(), 'prepared again as detection chose');
        $this->assertSame('php', $this->model->getDetails()['deploy_platform']);
        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_RECIPE] ?? null);
    }

    public function test_the_first_exception_is_rethrown_when_no_recipe_starts(): void
    {
        $candidates = count(PlatformCandidates::fallbackOrder(ProjectContext::at($this->dir)));
        $failures = [new \RuntimeException('first'), ...array_fill(0, $candidates - 1, $this->failed('later'))];

        $this->expectExceptionMessage('first');

        $this->fallback()->start($this->starts($failures), $this->prepare());
    }

    public function test_a_failure_of_the_host_is_not_retried_on_another_recipe(): void
    {
        $this->expectCleanup()->expects($this->never())->method('abortRunningDeploy');

        $result = $this->fallback()->start(
            $this->starts([$this->failed('write /var/lib/docker/tmp: no space left on device')]),
            $this->prepare()
        );

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame([null], $this->started);
        $this->assertSame(0, $this->prepared);
    }

    public function test_a_requested_recipe_is_not_replaced(): void
    {
        app(RecipeChoiceContext::class)->set('php');

        $this->fallback()->start($this->starts([$this->failed('broken')]), $this->prepare());

        $this->assertSame(['php'], $this->started);
        $this->assertSame(0, $this->prepared);
    }

    /** A shipped recipe's failed start is the deploy's, with its own error, and no compose it did not choose is tried. */
    public function test_a_failed_start_under_a_shipped_recipe_tries_nothing_else(): void
    {
        foreach (['composer.json', 'index.php', 'package.json', 'server.js'] as $file) {
            unlink($this->dir . '/' . $file);
        }
        mkdir($this->dir . '/docker-compose');
        file_put_contents($this->dir . '/docker-compose/docker-compose.yml', "services:\n  web:\n    image: nginx:1.27\n");
        $this->model->setDetails(['deploy_platform' => 'compose', 'git_repo' => 'https://github.com/tiledesk/tiledesk']);
        $this->expectCleanup()->expects($this->never())->method('abortRunningDeploy');

        $result = $this->fallback()->start(
            $this->starts([$this->failed('error: service server did not start (unhealthy)'), $this->ok()]),
            $this->prepare()
        );

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame('error: service server did not start (unhealthy)', $result['stderr']);
        $this->assertSame([null], $this->started);
        $this->assertSame(0, $this->prepared);
        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_RECIPE] ?? null);
    }

    public function test_a_cancel_stops_the_fallback(): void
    {
        $this->expectException(DeployCancelledException::class);

        $this->fallback()->start($this->starts([new DeployCancelledException('cancelled')]), $this->prepare());
    }

    /** A redeploy whose new version failed to build while the previous one serves: nothing is torn down under it. */
    public function test_a_kept_version_is_not_torn_down_between_recipes(): void
    {
        $this->expectCleanup()->expects($this->never())->method('abortRunningDeploy');
        $kept = ZeroDowntimeRedeploy::previousKept('', 'composer: Your requirements could not be resolved', 1);

        $result = $this->fallback()->start($this->starts([$kept, $this->ok()]), $this->prepare());

        $this->assertSame([null, 'express'], $this->started);
        $this->assertSame(0, $result['exit_code']);
    }

    /**
     * A redeploy whose new version ran and was turned away fails there: the previous version keeps serving, and no
     * other recipe replaces it.
     */
    public function test_a_new_version_turned_away_while_the_previous_serves_tries_nothing_else(): void
    {
        $this->expectCleanup()->expects($this->never())->method('abortRunningDeploy');
        $kept = $this->failed('The new version did not become healthy: it keeps restarting. The previous version is still serving.')
            + [ZeroDowntimeRedeploy::PREVIOUS_KEPT => true];

        $result = $this->fallback()->start($this->starts([$kept, $this->ok()]), $this->prepare());

        $this->assertSame([null], $this->started);
        $this->assertSame(0, $this->prepared);
        $this->assertSame(1, $result['exit_code']);
        $this->assertNotNull(ZeroDowntimeRedeploy::keptServing($result));
        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_RECIPE] ?? null);
    }

    /** A second copy of the new version left serving is not replaced by another recipe either. */
    public function test_a_new_version_copy_left_serving_tries_nothing_else(): void
    {
        $kept = $this->failed('The previous version could not be started again')
            + [ZeroDowntimeRedeploy::COPY_KEPT => true, ZeroDowntimeRedeploy::SERVING => 'The new version\'s second copy keeps serving the site'];

        $result = $this->fallback()->start($this->starts([$kept, $this->ok()]), $this->prepare());

        $this->assertSame([null], $this->started);
        $this->assertSame(1, $result['exit_code']);
    }

    /** The repository's own Dockerfile failed to start: its checkout is not served as files instead. */
    public function test_a_failed_dockerfile_is_not_replaced_by_its_checkout_served_as_files(): void
    {
        foreach (['composer.json', 'index.php', 'package.json', 'server.js'] as $file) {
            unlink($this->dir . '/' . $file);
        }
        file_put_contents($this->dir . '/Dockerfile', "FROM nginx:1.27-alpine\nCMD [\"sh\", \"-c\", \"exit 1\"]\n");
        file_put_contents($this->dir . '/index.html', '<!doctype html><title>v1</title>');
        $this->model->setDetails(['deploy_platform' => 'dockerfile']);

        $result = $this->fallback()->start($this->starts([$this->failed('the container exited with code 1'), $this->ok()]), $this->prepare());

        $this->assertSame([null], $this->started);
        $this->assertSame(0, $this->prepared);
        $this->assertSame(1, $result['exit_code']);
    }

    /** A compose app with its database that fails to start is not run without the database by its Dockerfile. */
    public function test_a_failed_compose_file_with_a_datastore_is_not_replaced_by_its_dockerfile(): void
    {
        foreach (['composer.json', 'index.php', 'package.json', 'server.js'] as $file) {
            unlink($this->dir . '/' . $file);
        }
        file_put_contents($this->dir . '/Dockerfile', "FROM python:3.12-slim\nCOPY . /app\nCMD [\"python\", \"/app/app.py\"]\n");
        file_put_contents($this->dir . '/docker-compose.yml', "services:\n  app:\n    build: .\n    depends_on: [db]\n  db:\n    image: postgres:16\n");
        $this->model->setDetails(['deploy_platform' => 'compose']);
        $error = 'environment variable "DB_PASSWORD" required by secret "project_db_password" is not set';

        $result = $this->fallback()->start($this->starts([$this->failed($error), $this->ok()]), $this->prepare());

        $this->assertSame([null], $this->started);
        $this->assertSame(0, $this->prepared);
        $this->assertSame($error, $result['stderr']);
    }

    /** Falling back to the recipe detection picks first is not worth remembering. */
    public function test_a_start_on_the_detected_recipe_forgets_an_older_fallback(): void
    {
        $this->model->setDetails([RecipeFallback::DETAIL_RECIPE => 'express']);

        $this->fallback()->start($this->starts([$this->ok()]), $this->prepare());

        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_RECIPE]);
    }

    /** Node detected, Bun asked for, and only npm installs: npm on the same recipe comes before any other recipe. */
    public function test_another_package_manager_is_tried_before_another_recipe(): void
    {
        $this->bunProjectWithPackageLock();

        $result = $this->fallback()->start(
            $this->starts([$this->failed('error: install script from "better-sqlite3" exited with 1'), $this->ok()]),
            $this->prepare()
        );

        $this->assertSame([null, 'express+npm'], $this->started);
        $this->assertSame(0, $result['exit_code']);
        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_RECIPE] ?? null, "express is what detection picks");
        $this->assertSame('npm', $this->model->getDetails()[RecipeFallback::DETAIL_PACKAGE_MANAGER]);
    }

    /** Every package manager of every recipe, recipes by priority, each recipe first with the one detection picks. */
    public function test_every_recipe_is_tried_with_every_package_manager(): void
    {
        $this->bunProjectWithPackageLock();

        $this->fallback()->start($this->starts(array_fill(0, 4, $this->failed('broken'))), $this->prepare());

        $this->assertSame([null, 'express+npm', 'node', 'node+npm'], $this->started);
        $this->assertNull(app(RecipeChoiceContext::class)->packageManager(), 'prepared again as the first attempt was');
        $this->assertSame(['express', 'bun'], [
            $this->model->getDetails()['deploy_platform'],
            $this->model->getDetails()[RecipeFallback::DETAIL_DEPLOYED_PACKAGE_MANAGER],
        ]);
        $this->assertNull($this->model->getDetails()[RecipeFallback::DETAIL_PACKAGE_MANAGER] ?? null);
    }

    /** A requested recipe stays, but its package manager may still change. */
    public function test_a_requested_recipe_still_tries_its_other_package_managers(): void
    {
        $this->bunProjectWithPackageLock();
        app(RecipeChoiceContext::class)->set('node');
        $this->model->setDetails(['deploy_platform' => 'node']);

        $this->fallback()->start($this->starts([$this->failed('broken'), $this->failed('broken')]), $this->prepare());

        $this->assertSame(['node', 'node+npm'], $this->started);
    }

    /** A Node recipe on a project with one lockfile has no other package manager to try. */
    public function test_a_single_lockfile_gives_no_package_manager_attempt(): void
    {
        $this->bunProjectWithPackageLock();
        unlink($this->dir . '/package-lock.json');

        $this->fallback()->start($this->starts([$this->failed('broken'), $this->failed('broken')]), $this->prepare());

        $this->assertSame([null, 'node'], $this->started);
    }

    private function bunProjectWithPackageLock(): void
    {
        foreach (['composer.json', 'index.php'] as $file) {
            unlink($this->dir . '/' . $file);
        }
        file_put_contents($this->dir . '/package.json', '{"packageManager":"bun@1.1.0","scripts":{"start":"node server.js"},"dependencies":{"express":"^4","better-sqlite3":"^9"}}');
        file_put_contents($this->dir . '/bun.lockb', 'binary');
        file_put_contents($this->dir . '/package-lock.json', '{"lockfileVersion":3}');
        $this->model->setDetails(['deploy_platform' => 'express', RecipeFallback::DETAIL_DEPLOYED_PACKAGE_MANAGER => 'bun']);
    }

    /** A failure no rule explains is named by its last line, where a build prints its error. */
    public function test_an_unexplained_failure_is_named_by_its_last_line(): void
    {
        $failed = RecipeAttempt::run(fn (): array => $this->failed("> app@1.0.0 postinstall\nadded 126 packages\n> tsc\nserver/scheduler.ts(61,33): error TS2503: Cannot find namespace 'cron'."));

        $this->assertSame("server/scheduler.ts(61,33): error TS2503: Cannot find namespace 'cron'.", $failed->reason());
        $this->assertNull($failed->rule());
    }

    private function fallback(): RecipeFallback
    {
        return new RecipeFallback($this->dind());
    }

    private function dind(): Dind
    {
        $dind = $this->createStub(Dind::class);
        $dind->method('username')->willReturn('rf-' . bin2hex(random_bytes(3)));
        $dind->method('userModel')->willReturn($this->model);
        $dind->method('userAppDirPath')->willReturn($this->dir);
        $dind->method('project')->willReturn($this->aggregate());
        $dind->method('shell')->willReturn(new ShellOperations($dind));

        return $dind;
    }

    /** A mock only where a test says what cleanup it expects; a stub otherwise. */
    private function aggregate(): ProjectAggregate
    {
        return $this->aggregate ??= $this->createStub(ProjectAggregate::class);
    }

    private function expectCleanup(): ProjectAggregate&MockObject
    {
        $mock = $this->createMock(ProjectAggregate::class);
        $this->aggregate = $mock;

        return $mock;
    }

    /**
     * @param  list<array<string, mixed>|\Exception>  $outcomes
     */
    private function starts(array $outcomes): \Closure
    {
        return function () use (&$outcomes): array {
            $choice = app(RecipeChoiceContext::class);
            $this->started[] = $choice->packageManager() === null ? $choice->get() : $choice->get() . '+' . $choice->packageManager();
            $outcome = array_shift($outcomes) ?? $this->failed('no more outcomes scripted');
            if ($outcome instanceof \Exception) {
                throw $outcome;
            }

            return $outcome;
        };
    }

    /** Freezes what a real prepare would: the recipe asked for, and the package manager a Node recipe installs with. */
    private function prepare(): \Closure
    {
        return function (): void {
            $this->prepared++;
            $recipe = app(RecipeChoiceContext::class)->get() ?? PlatformCandidates::fallbackOrder(ProjectContext::at($this->dir))[0];
            $context = ProjectContext::at($this->dir);
            $this->model->setDetails([
                'deploy_platform' => $recipe,
                RecipeFallback::DETAIL_DEPLOYED_PACKAGE_MANAGER => in_array($recipe, ['express', 'node'], true)
                    ? JsPackageManager::detectPackageManager($context->files, $context->package() ?? [])
                    : null,
            ]);
        };
    }

    /** @return array{stdout: string, stderr: string, exit_code: int} */
    private function ok(): array
    {
        return ['stdout' => 'started', 'stderr' => '', 'exit_code' => 0];
    }

    /** @return array{stdout: string, stderr: string, exit_code: int} */
    private function failed(string $why): array
    {
        return ['stdout' => '', 'stderr' => $why, 'exit_code' => 1];
    }

    /** @param  array<string, mixed>  $details */
    private function model(array $details): ModelsUser
    {
        $model = new class extends ModelsUser
        {
            public function save(array $options = []): bool
            {
                return true;
            }

            public function getMainDomain(): ?DomainModel
            {
                return null;
            }
        };
        $model->username = 'rf';
        $model->setDetails(array_merge(['template' => 'dind'], $details));

        return $model;
    }
}
