<?php

namespace Tests\Unit\System\Project\Deployment;

use PHPUnit\Framework\TestCase;

class DindDeployMechanicsTest extends TestCase
{
    private string $coreAppRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreAppRoot = dirname(__DIR__, 5) . '/app';
    }

    public function test_workflow_defaults_to_dind_deploy_mechanics_not_lib_user(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DeploymentWorkflow.php');
        $this->assertStringContainsString('DindDeployMechanics', $source);
        $this->assertStringNotContainsString('new LibUserDeployMechanics', $source);
    }

    public function test_dind_deploy_mechanics_never_forwards_to_lib_connect(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php');
        $this->assertStringNotContainsString('->connect()', $source);
        $this->assertStringNotContainsString('LibUserDeployMechanics', $source);
    }

    public function test_prepare_from_source_lives_on_dind_not_git_repository(): void
    {
        $dind = file_get_contents($this->coreAppRoot . '/System/Project/Dind.php');
        $this->assertStringContainsString('prepareFromSources', $dind);
        $this->assertStringContainsString('PrepareFromSource', $dind);

        $git = file_get_contents($this->coreAppRoot . '/System/Project/Dind/Source/GitRepository.php');
        $this->assertStringNotContainsString('PrepareFromSource', $git);
        $this->assertStringNotContainsString('DetectProjectStrategy', $git);
    }

    public function test_ingest_composes_git_clone_then_prepare(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php');
        $this->assertStringContainsString('preCheckFromSources', $source);
        $this->assertStringContainsString('cloneConfiguredRepository', $source);
        $this->assertStringContainsString('prepareFromSources', $source);
    }

    public function test_engine_artifact_exclude_is_written_after_ingest_and_after_checkout_rebuild_reprepare(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php');

        // ingestForWipeRebuild too: its re-clone starts from a fresh
        // .git/info/exclude, and nothing later in the rebuild writes the block.
        // Found live (ticket 08, scenario H): a POST /rebuild left every Engine
        // Artifact untracked in `git status`.
        foreach (['ingestApplicationSource', 'reprepareApplicationFromCheckout', 'ingestForWipeRebuild'] as $method) {
            $body = $this->existingMethodBody($source, $method);
            $prepare = strpos($body, 'prepareFromSources()');
            $exclude = strpos($body, 'excludeEngineArtifacts()');
            $this->assertNotFalse($prepare, $method);
            $this->assertNotFalse($exclude, $method);
            $this->assertGreaterThan($prepare, $exclude, "{$method} writes the block after preparing the app");
        }

        $helper = $this->existingMethodBody($source, 'excludeEngineArtifacts');
        $this->assertStringContainsString('hasGitProject()', $helper);
        $this->assertStringContainsString('EngineArtifactExclude::forProject', $helper);
    }

    private function existingMethodBody(string $source, string $method): string
    {
        $this->assertSame(1, preg_match('/function ' . $method . '\(.*?\r?\n    \}\r?\n/s', $source, $m), $method);

        return $m[0];
    }

    public function test_source_rebuild_uses_project_outer_helpers_and_wipe_ingest(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php');
        $this->assertStringContainsString('function syncHostingForSourceRebuild', $source);
        $this->assertStringContainsString('function ingestForWipeRebuild', $source);
        $this->assertStringContainsString('prepareLinuxIsolation', $source);
        $this->assertStringContainsString('recreateOuterCompose', $source);
        $this->assertStringContainsString('importProjectArchive', $source);
    }

    // The bare "prepareFromSources appears somewhere" assertion above stays green even if the
    // git-clone branch of ingestForWipeRebuild forgets to bootstrap, because the else branch and
    // reprepareApplicationFromCheckout() also call it. Check the git branch specifically.
    public function test_wipe_rebuild_bootstraps_the_checkout_after_recloning_it(): void
    {
        $source = file_get_contents(
            $this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php'
        );

        $body = self::methodBody($source, 'ingestForWipeRebuild');

        $branchStart = strpos($body, 'if (');
        $branchEnd = strpos($body, '} else {');
        $this->assertNotFalse($branchStart, 'ingestForWipeRebuild no longer branches.');
        $this->assertNotFalse($branchEnd, 'ingestForWipeRebuild no longer branches.');

        $gitBranch = substr($body, $branchStart, $branchEnd - $branchStart);

        $this->assertStringContainsString(
            'cloneConfiguredRepository',
            $gitBranch,
            'The wipe rebuild branch is expected to re-clone the repository.'
        );
        $this->assertStringContainsString(
            'prepareFromSources',
            $gitBranch,
            'ingestForWipeRebuild re-clones over the directory the app config wrote into '
            . 'but never bootstraps the checkout again.'
        );
    }

    // A wipe rebuild deletes ~/project while containers may still be bind-mounted under it;
    // the app must be stopped first or those mounts go stale.
    public function test_wipe_rebuild_stops_the_app_before_deleting_its_directory(): void
    {
        $source = file_get_contents(
            $this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php'
        );

        $body = self::methodBody($source, 'syncHostingForSourceRebuild');
        $this->assertNotSame('', $body, 'syncHostingForSourceRebuild() is the wipe step; it should still exist.');

        $stopAt = strpos($body, 'stopApplicationBeforeWipe');
        $this->assertNotFalse($stopAt, 'syncHostingForSourceRebuild() should stop the app before clearing ~/project.');

        $isolationAt = strpos($body, 'prepareLinuxIsolation');
        $this->assertNotFalse($isolationAt, 'prepareLinuxIsolation missing from the wipe step.');
        $this->assertLessThan(
            $isolationAt,
            $stopAt,
            'The app has to be stopped before the project directory is cleared.'
        );

        $stop = self::methodBody($source, 'stopApplicationBeforeWipe');
        $this->assertStringContainsString("'down'", $stop, 'The pre-wipe stop should bring the inner compose project down.');
        $this->assertStringNotContainsString(
            "'-v'",
            $stop,
            "The pre-wipe stop must not pass -v; that removes the account's named volumes."
        );
    }

    // A clean deploy is recorded through User::markDeploySucceeded(), which stores the empty
    // warnings list along with the status; writing the status by hand is how the list went
    // missing in the first place.
    public function test_persist_success_records_through_the_model_helper(): void
    {
        $source = file_get_contents(
            $this->coreAppRoot . '/System/Project/Deployment/DindDeployMechanics.php'
        );

        $body = self::methodBody($source, 'persistSuccess');
        $this->assertStringContainsString('markDeploySucceeded()', $body);
        $this->assertStringNotContainsString("'deployment_status'", $body);
    }

    // Ends at the next method of any visibility, so a private method after the target
    // doesn't get swallowed into the body and pass an assertion for the wrong reason.
    private static function methodBody(string $source, string $method): string
    {
        $start = strpos($source, "function {$method}(");
        if ($start === false) {
            return '';
        }

        preg_match(
            '/\n    (?:public|protected|private)(?: static)? function /',
            $source,
            $match,
            PREG_OFFSET_CAPTURE,
            $start + 10
        );
        $end = $match[0][1] ?? false;

        return $end === false ? substr($source, $start) : substr($source, $start, $end - $start);
    }
}
