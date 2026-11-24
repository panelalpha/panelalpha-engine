<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use PHPUnit\Framework\TestCase;

/**
 * A static build that wrote its page into several directories (cloudflare/cloudflare-os: a workspace whose root
 * `build` compiles every package) has no single site to serve, which HostCompile refuses to guess; the failure said
 * only that dist/index.html was missing. A build that wrote none is left alone, since the deploy log names what it
 * did write.
 */
class DeployFailureExplainerStaticOutputTest extends TestCase
{
    public function test_several_written_pages_name_the_directories_and_the_way_out(): void
    {
        $message = 'Static build finished but dist/index.html is missing; the build wrote index.html in several '
            .'directories: packages/gatekeeper-context/dist-app/app, packages/gatekeeper-scheduler/dist-app/app, '
            .'packages/workshop-frontend/dist';

        $this->assertSame([
            'rule' => 'static-build-several-sites',
            'message' => 'The build succeeded but wrote a page into several directories (packages/gatekeeper-context/dist-app/app, '
                .'packages/gatekeeper-scheduler/dist-app/app, packages/workshop-frontend/dist), so there is no single site '
                .'to serve: the repository builds several applications at once. Build only the one that is the site and '
                .'have it write dist/index.html, or deploy the project with a strategy that runs its own server.',
        ], DeployFailureExplainer::match($message));
    }

    public function test_the_line_is_found_inside_a_deploy_log_too(): void
    {
        $match = DeployFailureExplainer::match(
            "Deploy failed: Failed to start app: Static build finished but dist/index.html is missing; the build wrote "
            ."index.html in several directories: apps/web/dist, apps/admin/dist\n"
        );

        $this->assertSame('static-build-several-sites', $match['rule'] ?? null);
        $this->assertStringContainsString('(apps/web/dist, apps/admin/dist)', $match['message']);
    }

    public function test_a_build_that_wrote_no_page_is_left_to_the_deploy_log(): void
    {
        $this->assertNull(DeployFailureExplainer::match('Static build finished but dist/index.html is missing'));
    }
}
