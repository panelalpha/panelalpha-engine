<?php

namespace Tests\Unit\WordpressMigration;

use App\Lib\Http\OutboundUrl;
use App\System\Project\PhpHosting\WordPress\Migration\CurlSourceHttpClient;
use App\System\Project\PhpHosting\WordPress\Migration\SourceCheckFailed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * How the source client follows redirects hop by hop: which method, body and
 * headers a hop keeps, and how many hops it takes before giving up.
 */
class WordpressMigrationRedirectHopsTest extends TestCase
{
    use ServesSourceSite;

    private const ROUTER = <<<'PHP'
        <?php
        $uri = $_SERVER['REQUEST_URI'];
        $codes = ['/301' => 301, '/302' => 302, '/303' => 303, '/307' => 307, '/308' => 308];
        if (isset($codes[$uri])) {
            header('Location: /echo', true, $codes[$uri]);
            exit;
        }
        $elsewhere = ['/other-301' => [301, 'other.example'], '/other-307' => [307, 'other.example'], '/other-308' => [308, 'other.example'], '/www-308' => [308, 'www.wp.example']];
        if (isset($elsewhere[$uri])) {
            header('Location: http://'.$elsewhere[$uri][1].':'.$_SERVER['SERVER_PORT'].'/echo', true, $elsewhere[$uri][0]);
            exit;
        }
        if (preg_match('#^/chain/(\d+)$#', $uri, $m) === 1 && (int) $m[1] > 0) {
            header('Location: /chain/'.((int) $m[1] - 1), true, 302);
            exit;
        }
        if ($uri === '/no-location') {
            http_response_code(302);
            echo 'stay';
            exit;
        }
        if ($uri === '/missing') {
            http_response_code(404);
            echo 'gone';
            exit;
        }
        echo $_SERVER['REQUEST_METHOD'].'|'.file_get_contents('php://input').'|'.($_SERVER['HTTP_X_TEST'] ?? '-').'|'.$uri;
        PHP;

    protected function tearDown(): void
    {
        $this->stopSourceSite();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function hops(): array
    {
        return [
            '301 becomes a plain GET' => ['/301', 'GET||-|/echo'],
            '302 becomes a plain GET' => ['/302', 'GET||-|/echo'],
            '303 becomes a plain GET' => ['/303', 'GET||-|/echo'],
            '307 keeps the request' => ['/307', 'POST|payload|kept|/echo'],
            '308 keeps the request' => ['/308', 'POST|payload|kept|/echo'],
        ];
    }

    #[DataProvider('hops')]
    public function test_a_redirected_post_keeps_or_drops_its_method_body_and_headers(string $path, string $expected): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::ROUTER);

        $response = $client->request('post', $url.$path, 'payload', ['X-Test' => 'kept']);

        $this->assertSame($expected, $response->body);
        $this->assertSame(200, $response->status);
        $this->assertSame($url.'/echo', $response->effectiveUrl);
    }

    public function test_five_redirects_are_followed(): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::ROUTER);

        $response = $client->request('GET', $url.'/chain/5');

        $this->assertSame('GET||-|/chain/0', $response->body);
        $this->assertSame($url.'/chain/0', $response->effectiveUrl);
    }

    public function test_a_sixth_redirect_is_refused(): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::ROUTER);

        try {
            $client->request('GET', $url.'/chain/6');
            $this->fail('A sixth redirect must be refused');
        } catch (RuntimeException $e) {
            $this->assertSame(RuntimeException::class, $e::class);
            $this->assertSame('Source WordPress URL redirected too many times', $e->getMessage());
        }
    }

    public function test_a_redirect_status_without_a_location_is_the_answer(): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::ROUTER);

        $response = $client->request('GET', $url.'/no-location');

        $this->assertSame('stay', $response->body);
        $this->assertSame(302, $response->status);
        $this->assertSame($url.'/no-location', $response->effectiveUrl);
    }

    public function test_an_error_status_is_the_answer(): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::ROUTER);

        $response = $client->request('GET', $url.'/missing');

        $this->assertSame('gone', $response->body);
        $this->assertSame(404, $response->status);
    }

    public function test_a_multipart_post_is_sent_once_without_redirects(): void
    {
        [$client, $url] = $this->clientOnSourceSite(self::ROUTER);

        $response = $client->postMultipart($url.'/echo', ['field' => 'value']);

        $this->assertStringStartsWith('POST|', $response->body);
        $this->assertStringEndsWith('|-|/echo', $response->body);
        $this->assertSame(200, $response->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function requestKeepingRedirectsToAnotherHost(): array
    {
        return [
            '307 to another host' => ['/other-307'],
            '308 to another host' => ['/other-308'],
        ];
    }

    #[DataProvider('requestKeepingRedirectsToAnotherHost')]
    public function test_a_307_or_308_to_another_host_is_refused_before_the_request_goes_there(string $path): void
    {
        [$client, $url] = $this->clientReachingOtherHosts();

        try {
            $response = $client->request('POST', $url.$path, 'payload', ['X-Test' => 'kept']);
            $this->fail('The request reached other.example as '.$response->body);
        } catch (SourceCheckFailed $e) {
            $this->assertSame(SourceCheckFailed::UNREACHABLE, $e->errorType());
            $this->assertSame(
                'The source WordPress site redirects to http://other.example:'.parse_url($url, PHP_URL_PORT).'; enter that URL as the source URL',
                $e->getMessage(),
            );
        }
    }

    public function test_a_308_to_the_www_twin_keeps_the_request(): void
    {
        [$client, $url] = $this->clientReachingOtherHosts();

        $response = $client->request('POST', $url.'/www-308', 'payload', ['X-Test' => 'kept']);

        $this->assertSame('POST|payload|kept|/echo', $response->body);
        $this->assertSame('http://www.wp.example:'.parse_url($url, PHP_URL_PORT).'/echo', $response->effectiveUrl);
    }

    public function test_a_301_to_another_host_still_becomes_a_plain_get(): void
    {
        [$client, $url] = $this->clientReachingOtherHosts();

        $response = $client->request('POST', $url.'/other-301', 'payload', ['X-Test' => 'kept']);

        $this->assertSame('GET||-|/echo', $response->body);
        $this->assertSame('http://other.example:'.parse_url($url, PHP_URL_PORT).'/echo', $response->effectiveUrl);
    }

    /**
     * The source site's client, with `other.example` and `www.wp.example` allowed to reach the same server.
     *
     * @return array{0: CurlSourceHttpClient, 1: string}
     */
    private function clientReachingOtherHosts(): array
    {
        $site = $this->clientOnSourceSite(self::ROUTER);
        OutboundUrl::setPolicyForTests(false, [self::SOURCE_HOST, 'other.example', 'www.wp.example']);

        return $site;
    }
}
