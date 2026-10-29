<?php

namespace Tests\Unit\WordpressMigration;

use App\System\Project\PhpHosting\WordPress\Migration\SourceFetchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestNetwork;

/**
 * A source that answers with a redirect does not take the Engine along to an
 * internal address: each hop is checked before curl connects, and a refused
 * hop gets the guard's one answer, never curl's view of what listens there.
 */
class WordpressMigrationSourceRedirectTest extends TestCase
{
    use ServesSourceSite;

    private const ROUTER = <<<'PHP'
        <?php
        $targets = ['/to-lan' => 'http://%s:8006/', '/to-metadata' => 'http://%s/latest/meta-data', '/to-docker' => 'http://%s:5000/', '/to-home' => '/home'];
        if (isset($targets[$_SERVER['REQUEST_URI']])) {
            header('Location: '.$targets[$_SERVER['REQUEST_URI']], true, 302);
            exit;
        }
        echo 'home';
        PHP;

    private static function router(): string
    {
        return sprintf(self::ROUTER, TestNetwork::private(1), TestNetwork::METADATA, TestNetwork::container(5));
    }

    protected function tearDown(): void
    {
        $this->stopSourceSite();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function internalRedirects(): array
    {
        return [
            'lan' => ['/to-lan'],
            'metadata' => ['/to-metadata'],
            'docker network' => ['/to-docker'],
        ];
    }

    #[DataProvider('internalRedirects')]
    public function test_a_redirect_to_an_internal_address_is_refused_before_curl_connects(string $path): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::router());
        $started = microtime(true);

        try {
            $client->request('GET', $url.$path);
            $this->fail('The redirect must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame(SourceFetchGuard::REFUSED, $e->getMessage());
        }
        $this->assertLessThan(5, microtime(true) - $started);
    }

    public function test_a_redirect_on_the_source_itself_is_followed(): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::router());

        $this->assertSame('home', $client->request('GET', $url.'/to-home')->body);
    }
}
