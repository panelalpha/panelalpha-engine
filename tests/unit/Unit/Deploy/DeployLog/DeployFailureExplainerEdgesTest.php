<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use PHPUnit\Framework\TestCase;

/** Image references the explainer cannot take apart, and a rebuild that failed without saying why. */
class DeployFailureExplainerEdgesTest extends TestCase
{
    public function test_a_reference_with_no_repository_name_is_not_taken_for_one_this_log_builds(): void
    {
        $output = "#3 ERROR: failed to resolve reference \"ghcr.io/acme/:v1\": ghcr.io/acme/:v1: not found\n"
            . "#4 naming to ghcr.io/acme/:v1 done\n";

        $this->assertSame([
            'rule' => 'base-image-unavailable',
            'message' => 'The base image ghcr.io/acme/:v1 could not be downloaded: its registry says it does not exist. '
                . 'That tag or repository was never published or has been removed, so the project has to name one that exists.',
        ], DeployFailureExplainer::match($output));
    }

    public function test_a_capitalised_name_without_a_registry_part_is_not_called_a_registry_address(): void
    {
        $match = DeployFailureExplainer::match('failed to solve: failed to resolve reference "MyApp:latest": not found');

        $this->assertNotNull($match);
        $this->assertSame('base-image-unavailable', $match['rule']);
        $this->assertStringNotContainsString('address of a registry', $match['message']);
    }

    public function test_a_failed_rebuild_with_no_reason_says_how_to_retry(): void
    {
        $match = DeployFailureExplainer::match('The base image panelalpha/php:8.3-apache-bookworm-pa20260924 is not on this server; rebuilding it failed. ');

        $this->assertSame([
            'rule' => 'base-image-rebuild-failed',
            'message' => 'The base image panelalpha/php:8.3-apache-bookworm-pa20260924 is not on this server and is one this engine '
                . 'builds; rebuilding it did not work, so the host build had nothing to run. Deploy again to rebuild it, '
                . 'or prewarm it so it is built ahead of a deploy.',
        ], $match);
    }
}
