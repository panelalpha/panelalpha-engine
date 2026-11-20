<?php

namespace Tests\Integration\System\Privileged;

use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;

final class HostBrokerTest extends TestCase
{
    public function test_the_project_cache_directory_is_made_roots_before_anything_is_handed_to_the_account(): void
    {
        $script = LocalRoot::script(Privileged::host()->prepareProjectCache('alice', '1001:1002'));
        $dir = "'/var/cache/panelalpha/projects/alice'";

        $this->assertStringStartsWith(
            "mkdir -p -- {$dir} && chown -h -- '0:1002' {$dir} && chmod -- 750 {$dir} && touch -- {$dir} || exit 1; ",
            $script
        );
        $this->assertStringNotContainsString('chown -R', $script);
        $this->assertStringContainsString(
            "; for cache in '/var/cache/panelalpha/projects/alice/node_modules' '/var/cache/panelalpha/projects/alice/npm' ",
            $script
        );
        $this->assertStringEndsWith(
            "'/var/cache/panelalpha/projects/alice/gradle'; do "
            .'if [ -L "$cache" ] || { [ -e "$cache" ] && [ ! -d "$cache" ]; }; then rm -rf -- "$cache" || exit 1; fi; '
            ."mkdir -p -- \"\$cache\" && chown -h -- '1001:1002' \"\$cache\" || exit 1; done",
            $script
        );
    }

    public function test_the_host_readings_quote_each_value(): void
    {
        $this->assertStringContainsString("set -- 'shop' '/home/shop' ", LocalRoot::script(Privileged::host()->projectDisk(['shop' => '/home/shop/'])));
        $this->assertStringContainsString(
            "host='shop.example.com'\naddr='".TestNetwork::public(7)."'",
            LocalRoot::script(Privileged::host()->edgeProbe('shop.example.com', TestNetwork::public(7), 4, true)),
        );
    }
}
