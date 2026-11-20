<?php

namespace Tests\Integration;

use App\Lib\HttpAcmeChallengeStore;
use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpAcmeChallengeStoreFixtures;

/** The challenge store against an in-memory host: tokens, contents, and what it removes. */
class HttpAcmeChallengeStoreTest extends TestCase
{
    use HttpAcmeChallengeStoreFixtures;

    public function test_tokens_are_sorted_and_names_outside_the_alphabet_ignored(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([
            self::DIR.'/zeta' => 'zeta.key',
            self::DIR.'/alpha' => 'alpha.key',
            self::DIR.'/not a token' => 'x',
        ]));

        $this->assertSame(['alpha', 'zeta'], $store->listTokens('site.test'));
        $this->assertTrue($store->enabled('site.test'));
        $this->assertSame([
            ['token' => 'alpha', 'content' => 'alpha.key'],
            ['token' => 'zeta', 'content' => 'zeta.key'],
        ], $store->list('site.test'));
    }

    public function test_a_failed_listing_reads_as_no_tokens(): void
    {
        $store = new HttpAcmeChallengeStore(self::host([self::DIR.'/alpha' => 'a'], 2));

        $this->assertSame([], $store->listTokens('site.test'));
        $this->assertFalse($store->enabled('site.test'));
    }

    public function test_the_first_put_enables_the_domain_and_writes_a_world_readable_file(): void
    {
        $system = self::host();
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_enabled' => true], $store->put('site.test', 'alpha', 'alpha.key'));
        $this->assertSame([self::DIR], $system->dirs);
        $this->assertContains('put '.self::DIR.'/alpha 644', $system->commands);
        $this->assertSame('alpha.key', $store->get('site.test', 'alpha'));

        $this->assertSame(['became_enabled' => false], $store->put('site.test', 'beta', 'beta.key'));
    }

    public function test_deleting_the_last_token_removes_the_directory_and_disables_the_domain(): void
    {
        $system = self::host([self::DIR.'/alpha' => 'alpha.key']);
        $system->dirs = [self::DIR];
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_disabled' => true, 'deleted' => true], $store->delete('site.test', 'alpha'));
        $this->assertContains(Privileged::files()->removeIfExists(self::DIR.'/alpha'), $system->commands);
        $this->assertContains(Privileged::files()->removeTree(self::DIR), $system->commands);
        $this->assertSame([], $system->dirs);
    }

    public function test_deleting_one_of_two_tokens_keeps_the_domain_enabled(): void
    {
        $system = self::host([self::DIR.'/alpha' => 'a', self::DIR.'/beta' => 'b']);
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_disabled' => false, 'deleted' => true], $store->delete('site.test', 'alpha'));
        $this->assertNotContains(Privileged::files()->removeTree(self::DIR), $system->commands);
        $this->assertSame(['beta'], $store->listTokens('site.test'));
    }

    public function test_delete_all_removes_the_tree_and_counts_the_tokens(): void
    {
        $system = self::host([self::DIR.'/alpha' => 'a', self::DIR.'/beta' => 'b']);
        $store = new HttpAcmeChallengeStore($system);

        $this->assertSame(['became_disabled' => true, 'deleted_count' => 2], $store->deleteAll('site.test'));
        $this->assertSame([], $store->listTokens('site.test'));
        $this->assertSame(['became_disabled' => false, 'deleted_count' => 0], $store->deleteAll('site.test'));
    }
}
