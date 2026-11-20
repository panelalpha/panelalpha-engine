<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\CacheManager\PublishedBaseImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineAccount;
use App\System\Privileged;
use App\System\Privileged\Scripts\ImagePipelines;
use PHPUnit\Framework\TestCase;

/**
 * The host-side image requests the store hands the gateway, and a seed with nothing safe to import.
 */
class DindImageStoreHostCommandsTest extends TestCase
{
    private function account(): EngineAccount
    {
        return new EngineAccount('demo', '/home/demo', '33:33');
    }

    public function test_a_background_build_asks_for_the_published_copy_of_its_tag(): void
    {
        $tag = 'panelalpha/php:8.3-cli-bookworm-pa1234567890';

        $argv = (new DindImageStore)->hostBuildInBackgroundArgv($tag, "FROM php:8.3-cli-bookworm\n");

        $this->assertSame(Privileged::scripts()->buildImageOnHostInBackground($tag, "FROM php:8.3-cli-bookworm\n", PublishedBaseImage::ref($tag)), $argv);
        $this->assertStringContainsString(PublishedBaseImage::ref($tag) ?? $tag, implode(' ', $argv));
    }

    public function test_pulling_a_published_base_is_the_pipelines_check_of_its_label(): void
    {
        $this->assertSame(
            (new ImagePipelines)->hostPullPublished('panelalpha/php:8.3', 'ghcr.io/panelalpha/php:8.3', "FROM x\n"),
            (new DindImageStore)->hostPullPublishedCommand('panelalpha/php:8.3', 'ghcr.io/panelalpha/php:8.3', "FROM x\n")
        );
    }

    public function test_loading_from_the_host_names_the_account_and_the_image(): void
    {
        $argv = (new DindImageStore)->loadFromHostArgv($this->account(), 'redis:7');

        $this->assertSame(Privileged::scripts()->loadImageFromHost('demo', 'redis:7'), $argv);
        $this->assertStringContainsString('redis:7', implode(' ', $argv));
    }

    public function test_a_seed_with_no_safe_image_does_nothing(): void
    {
        $this->assertSame('true', (new DindImageStore)->parallelImportCommand($this->account(), ['', '-rf', 'evil; rm -rf /'], 4));
    }
}
