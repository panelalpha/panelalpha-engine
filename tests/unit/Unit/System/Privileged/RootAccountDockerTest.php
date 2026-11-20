<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\InvalidArgument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\RootAccountDockerFixtures;

final class RootAccountDockerTest extends TestCase
{
    use RootAccountDockerFixtures;

    /** @param  \Closure(): list<string>  $request */
    #[DataProvider('requests')]
    public function test_each_request_names_the_account_first(\Closure $request, array $expected): void
    {
        $this->assertSame($expected, $request());
    }

    public function test_the_lines_ask_the_same_actions(): void
    {
        $lines = Privileged::accountDockerLines('alice');

        $this->assertSame(self::CLIENT.' account-docker.hasImage -- alice node:22', $lines->hasImage('node:22'));
        $this->assertSame(self::CLIENT.' account-docker.images -- alice node:22', $lines->images('node:22'));
        $this->assertSame(self::CLIENT." account-docker.pull -- alice node:22 ''", $lines->pull('node:22'));
        $this->assertSame(self::CLIENT.' account-docker.tag -- alice a:1 b:2', $lines->tag('a:1', 'b:2'));
        $this->assertSame(self::CLIENT." account-docker.removeImages -- alice '' a:1", $lines->removeImage('a:1'));
    }

    /** @param  \Closure(): mixed  $build */
    #[DataProvider('refused')]
    public function test_a_value_the_broker_would_refuse_is_refused_before_it_is_asked(\Closure $build): void
    {
        $this->expectException(InvalidArgument::class);

        $build();
    }
}
