<?php

namespace Tests\Unit;

use App\Lib\HttpAcmeChallengeStore;
use App\System\Privileged;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpAcmeChallengeStoreFixtures;

/** The challenge store against an in-memory host: tokens, contents, and what it removes. */
class HttpAcmeChallengeStoreTest extends TestCase
{
    use HttpAcmeChallengeStoreFixtures;

    public function test_paths_are_below_the_engine_data_directory(): void
    {
        $store = new HttpAcmeChallengeStore(self::host());

        $this->assertSame('/engine/data/webserver/acme-challenges', $store->basePath());
        $this->assertSame(self::DIR, $store->domainDir('site.test'));
        $this->assertSame(self::DIR.'/tok-1_A', $store->challengePath('site.test', 'tok-1_A'));
    }

    public function test_a_domain_without_a_directory_has_no_tokens_and_is_disabled(): void
    {
        $store = new HttpAcmeChallengeStore(self::host());

        $this->assertSame([], $store->listTokens('site.test'));
        $this->assertSame([], $store->list('site.test'));
        $this->assertSame(
            ['http_acme_challenges_enabled' => false, 'http_acme_challenges_dir' => self::DIR],
            $store->templateVars('site.test')
        );
    }

    public function test_get_and_exists_read_one_token(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([self::DIR.'/alpha' => 'alpha.key']));

        $this->assertSame('alpha.key', $store->get('site.test', 'alpha'));
        $this->assertNull($store->get('site.test', 'beta'));
        $this->assertTrue($store->exists('site.test', 'alpha'));
        $this->assertFalse($store->exists('site.test', 'beta'));
    }

    public function test_put_refuses_a_token_that_already_exists(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([self::DIR.'/alpha' => 'alpha.key']));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Challenge token already exists');

        $store->put('site.test', 'alpha', 'other.key');
    }

    public function test_deleting_a_missing_token_changes_nothing(): void
    {
        $system = self::host();

        $this->assertSame(
            ['became_disabled' => false, 'deleted' => false],
            (new HttpAcmeChallengeStore($system))->delete('site.test', 'alpha')
        );
        $this->assertSame([], $system->commands);
    }

    public function test_delete_domain_dir_runs_only_when_the_directory_exists(): void
    {
        $system = self::host();
        $store = new HttpAcmeChallengeStore($system);
        $store->deleteDomainDir('site.test');
        $this->assertSame([], $system->commands);

        $system->dirs = [self::DIR];
        $store->deleteDomainDir('site.test');
        $this->assertSame([Privileged::files()->removeTree(self::DIR)], $system->commands);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'dot' => ['a.b'];
        yield 'slash' => ['../etc'];
        yield 'space' => ['a b'];
    }

    #[DataProvider('invalidTokens')]
    public function test_a_token_outside_the_base64url_alphabet_is_refused(string $token): void
    {
        $store = new HttpAcmeChallengeStore(self::host());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid ACME challenge token');

        $store->challengePath('site.test', $token);
    }

    public function test_empty_content_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Challenge content must not be empty');

        (new HttpAcmeChallengeStore(self::host()))->put('site.test', 'alpha', '');
    }

    public function test_content_over_the_maximum_length_is_refused(): void
    {
        $store = new HttpAcmeChallengeStore(self::host());
        $store->assertValidContent(str_repeat('a', HttpAcmeChallengeStore::MAX_CONTENT_LENGTH));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Challenge content exceeds maximum length');

        $store->assertValidContent(str_repeat('a', HttpAcmeChallengeStore::MAX_CONTENT_LENGTH + 1));
    }
}
