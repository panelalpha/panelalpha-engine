<?php

namespace Tests\Integration\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\EngineAddresses;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use App\System\Project\PhpHosting\WordPress\Migration\SourceIpPolicy;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * The connection test answers ok with what it found, or the error_type of the
 * failure, and runs its extra checks only once the source has been found.
 */
class WordpressMigrationProbeTest extends TestCase
{
    public function test_every_internal_source_gets_the_same_answer_whatever_listens_there(): void
    {
        $dns = static fn (string $host): array => $host === 'sites-db' ? [TestNetwork::container2(2)] : [];
        $policy = new SourceIpPolicy(new class extends EngineAddresses {
            public function ranges(): array
            {
                return [TestNetwork::routable(40)];
            }
        });
        $probe = new SourceProbe(static fn (SourceAccess $access) => new CurlSourceHttpClient($access->pin(), $dns, $policy), []);

        $answers = array_map(
            static fn (string $url): array => $probe->run(new SourceAccess(url: $url, username: 'admin', password: 'secret')),
            ['http://' . TestNetwork::container(5) . ':5000/', 'http://' . TestNetwork::private(1) . ':8006/', 'http://sites-db:3306/', 'https://' . TestNetwork::routable(40) . '/'],
        );

        $refused = ['ok' => false, 'error_type' => SourceCheckFailed::UNREACHABLE, 'message' => SourceFetchGuard::REFUSED];
        $this->assertSame([$refused, $refused, $refused, $refused], $answers);
    }
}
