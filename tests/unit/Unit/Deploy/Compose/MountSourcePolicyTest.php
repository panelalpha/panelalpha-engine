<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\MountSourcePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The bind rule on its own, without a checkout on disk, so the symlink check does not run. */
class MountSourcePolicyTest extends TestCase
{
    private const USER = 'shop';

    private const PROJECT_DIR = '/var/lib/panelalpha/projects/shop/project';

    /** @return array<string, array{mixed}> */
    public static function forbidden(): array
    {
        return [
            'the docker socket' => ['/var/run/docker.sock:/var/run/docker.sock'],
            'the socket directory' => ['/var/run:/var/run'],
            'the short run path' => ['/run:/run'],
            'the host root' => ['/:/host'],
            'a path outside the checkout' => ['/etc:/etc'],
            'the account home' => ['/home/shop:/data'],
            'a path that climbs out through dots' => ['/home/shop/project/../docker:/data'],
            'root home by tilde' => ['~/.ssh:/ssh'],
            'home by variable' => ['$HOME/.ssh:/ssh'],
            'a relative path out of the checkout' => ['../docker:/data'],
            'a long form with the socket as source' => [['type' => 'bind', 'source' => '/var/run/docker.sock', 'target' => '/s']],
            'a long form with the socket as target' => [['type' => 'bind', 'source' => './s', 'target' => '/var/run/docker.sock']],
            'a long form bind of a bare outside path' => [['type' => 'bind', 'source' => '/etc', 'target' => '/etc']],
            'a Windows drive path' => ['C:\\data:/data'],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function allowed(): array
    {
        return [
            'a named volume' => ['pgdata:/var/lib/postgresql/data'],
            'an anonymous volume' => ['/var/lib/mysql'],
            'a path in the checkout' => ['./data:/data'],
            'a bare name in a long form bind' => [['type' => 'bind', 'source' => 'data', 'target' => '/data']],
            'the panelalpha directory' => ['./.panelalpha/config:/config'],
            'the account panelalpha directory' => ['/home/shop/.panelalpha/config:/config'],
            'a path under the checkout in the account' => ['/home/shop/project/app:/app'],
            'a dotted path that stays in the checkout' => ['./a/../b:/b'],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_compose_mount_that_reaches_outside_the_account_tree_is_forbidden(mixed $volume): void
    {
        $this->assertTrue(MountSourcePolicy::isForbiddenMount($volume, [], self::USER, self::PROJECT_DIR));
    }

    #[DataProvider('allowed')]
    public function test_compose_mount_inside_the_checkout_or_a_named_volume_is_allowed(mixed $volume): void
    {
        $this->assertFalse(MountSourcePolicy::isForbiddenMount($volume, [], self::USER, self::PROJECT_DIR));
    }

    public function test_interpolated_source_is_checked_for_every_value_it_can_take(): void
    {
        $this->assertFalse(MountSourcePolicy::isForbiddenSourceIn('${DATA_DIR:-./data}', [], self::USER, self::PROJECT_DIR));
        $this->assertTrue(MountSourcePolicy::isForbiddenSourceIn('${DATA_DIR:-/var/run}', [], self::USER, self::PROJECT_DIR));
        $this->assertTrue(MountSourcePolicy::isForbiddenSourceIn('${DATA_DIR}', ['DATA_DIR' => ['./data', '/etc']], self::USER, self::PROJECT_DIR));
    }

    public function test_source_without_a_leading_dot_is_read_as_a_path(): void
    {
        $this->assertSame('./data', MountSourcePolicy::asPath('data'));
        $this->assertSame('/abs', MountSourcePolicy::asPath(' /abs '));
        $this->assertSame('~/x', MountSourcePolicy::asPath('~/x'));
        $this->assertSame('', MountSourcePolicy::asPath('  '));
    }
}
