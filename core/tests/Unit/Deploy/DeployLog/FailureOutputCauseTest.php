<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use PHPUnit\Framework\TestCase;

/**
 * A step's own line that names the cause has to lead the region select()
 * hands the explainer. When only BuildKit's `#N ERROR:` below it announced
 * itself, the region started there and the explainer saw the exit code alone.
 */
class FailureOutputCauseTest extends TestCase
{
    /**
     * minthcm, the step output as logged: the account daemon's
     * libnetwork socket was missing, so runc could not start the RUN step.
     */
    public function test_a_runc_run_failure_leads_over_the_steps_error_line(): void
    {
        $output = <<<'OUT'
#9 9.115 Fetched 17.1 MB in 1s (33.8 MB/s)
#9 DONE 23.9s
#10 [ 5/12] COPY docker/config/php-minthcm.ini /etc/php/8.2/mods-available/php-minthcm.ini
#11 [ 6/12] RUN ln -s /etc/php/8.2/mods-available/php-minthcm.ini /etc/php/8.2/cli/conf.d/20-minthcm.ini
#11 0.190 runc run failed: unable to start container process: error during container init: error running prestart hook #0: exit status 1, stdout: , stderr: dial unix /var/run/docker/libnetwork/fbd506163a34.sock: connect: no such file or directory
#11 0.190
#11 ERROR: process "/bin/sh -c ln -s /etc/php/8.2/mods-available/php-minthcm.ini /etc/php/8.2/cli/conf.d/20-minthcm.ini" did not complete successfully: exit code: 1
WARNING: current commit information was not captured by the build: failed to read current commit information with git rev-parse --is-inside-work-tree
failed to receive status: rpc error: code = Unavailable desc = error reading from server: EOF
OUT;

        $region = FailureOutput::select($output);

        $this->assertStringStartsWith('#11 0.190 runc run failed:', $region);
        $this->assertSame('build-daemon-fault', DeployFailureExplainer::match($region)['rule'] ?? null);
    }

    public function test_runc_create_still_leads(): void
    {
        $output = "#5 0.101 runc create failed: unable to start container process: exec: \"/bin/sh\": no such file\n"
            . "#5 ERROR: process \"/bin/sh -c true\" did not complete successfully: exit code: 1\n";

        $this->assertStringStartsWith('#5 0.101 runc create failed:', FailureOutput::select($output));
    }

    /**
     * hitobito, the failed step and compose's stderr from a deploy log:
     * `rails locales:patch_de` loads a rake task requiring annotate_rb, a gem
     * in the `metrics` group the Dockerfile's BUNDLE_WITHOUT leaves out. The
     * explainer is given what AppLauncher hands it: the step's own tail, then stderr.
     */
    public function test_a_ruby_load_error_names_the_missing_gem(): void
    {
        $dir = __DIR__ . '/../../../fixtures/failure-output/';
        $step = FailureOutput::failedBuildStep((string) file_get_contents($dir . 'hitobito.stdout'));
        $this->assertStringContainsString('LoadError: cannot load such file -- annotate_rb', $step);

        $region = FailureOutput::select($step . "\n" . (string) file_get_contents($dir . 'hitobito.stderr'));
        $match = DeployFailureExplainer::match($region);

        $this->assertStringStartsWith('#17 51.95 LoadError: cannot load such file -- annotate_rb', $region);
        $this->assertSame('ruby-gem-not-loaded', $match['rule'] ?? null);
        $this->assertStringContainsString('`annotate_rb`', $match['message']);
    }
}
