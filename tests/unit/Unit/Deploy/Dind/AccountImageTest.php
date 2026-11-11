<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\CacheManager\HostPrewarmPlan;
use App\Lib\Deploy\Dind\AccountImage;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class AccountImageTest extends TestCase
{
    /**
     * What the published tag was built from: a change to these files needs a new
     * tag named in AccountImage::REF, and its hash here.
     */
    private const PUBLISHED_CONTEXT = [
        'ghcr.io/panelalpha/engine-user-dind:v2.2.0-20261028' => 'f559a82e7f8c9b8e06c9c5ee9e7b64193605a8dab9bdd6d3b77556af07feb9f5',
    ];

    private const CONTEXT_FILES = ['Dockerfile', 'entrypoint.sh', 'wp-cli/wp-cli.phar'];

    public function test_an_image_already_on_the_host_is_used_before_a_pull_and_a_pull_before_a_build(): void
    {
        $command = AccountImage::ensureCommand('/opt/panelalpha/shared-hosting/users/my alice');
        $ref = AccountImage::REF;
        $root = LocalRoot::CLIENT;

        $this->assertSame(
            "{$root} lines.inspectImage -- {$ref} >/dev/null 2>&1"
                ." || {$root} lines.pullQuietly -- {$ref} >/dev/null"
                ." || {$root} lines.buildQuietly -- {$ref} '/opt/panelalpha/shared-hosting/users/my alice' >/dev/null",
            $command
        );
    }

    public function test_a_refresh_only_pulls_so_a_failure_keeps_the_current_image(): void
    {
        $this->assertSame(LocalRoot::CLIENT.' lines.pullQuietly -- '.AccountImage::REF.' >/dev/null', AccountImage::refreshCommand());
    }

    public function test_every_host_warms_it_outside_the_operator_selection(): void
    {
        $this->assertSame([AccountImage::REF], array_column(HostPrewarmPlan::required(), 'ref'));
        $this->assertNotContains(AccountImage::REF, array_column(HostPrewarmPlan::available(), 'ref'));
        $this->assertNotContains(AccountImage::REF, array_column(HostPrewarmPlan::catalog(), 'ref'));
    }

    public function test_only_other_tags_of_the_account_image_are_superseded(): void
    {
        $present = [
            AccountImage::REF,
            AccountImage::REPOSITORY.':v2.0.2',
            'ghcr.io/panelalpha/engine-core:20260603',
            'nginx:alpine',
        ];

        $this->assertSame([AccountImage::REPOSITORY.':v2.0.2'], AccountImage::superseded($present));
    }

    public function test_the_published_tag_was_built_from_the_template_accounts_fall_back_to(): void
    {
        $this->assertArrayHasKey(AccountImage::REF, self::PUBLISHED_CONTEXT, 'AccountImage::REF changed: record the hash of the files it was built from');
        $this->assertSame(
            self::PUBLISHED_CONTEXT[AccountImage::REF],
            $this->contextHash(),
            'templates/user/dind/project changed: publish a new engine-user-dind tag and set AccountImage::REF to it'
        );
    }

    private function contextHash(): string
    {
        $dir = dirname(__DIR__, 5).'/templates/user/dind/project';
        if (! is_file($dir.'/Dockerfile')) {
            $this->markTestSkipped('DinD project templates not available in this environment');
        }

        return hash('sha256', implode("\n", array_map(
            static fn (string $file): string => hash_file('sha256', $dir.'/'.$file),
            self::CONTEXT_FILES
        )));
    }
}
