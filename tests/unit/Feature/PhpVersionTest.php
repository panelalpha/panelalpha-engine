<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Illuminate\Support\Str;

/**
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class PhpVersionTest extends TestCase
{
    public function test_get_available_php_versions(): void
    {
        $this->authenticate();
        $response = $this->getJson("/api/php/available-versions");
        $response->assertStatus(200);
    }

    public function test_set_php_version(): void
    {
        /** @var string $username */
        $username = $this->getCache('user.username');
        /** @var string $domainName */
        $domainName = $this->getCache('domain.domain');
        /** @var string $ip */
        $ip = Setting::get('default_ipv4');

        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}");
        $documentRoot = $response->json('data.details.document_root');
        assert(is_string($documentRoot));

        $response = $this->getJson("/api/php/available-versions");
        $response->assertStatus(200);
        $result = $response->json();
        if (!is_array($result)) {
            $this->fail('invalid api response: ' . $response->content());
            return;
        }
        /** @var array<string> $versions */
        $versions = $result['data'];

        foreach ($versions as $version) {
            $response = $this->putJson("/api/domains/{$domainName}/php-version", [
                'version' => $version,
            ]);
            $response->assertStatus(204);

            // dump('version changed to ' . $version);

            $url = "https://{$domainName}";
            $response = Http::withOptions([
                'verify' => false,
                'curl' => [
                    CURLOPT_RESOLVE => ["{$domainName}:443:" . $ip],
                ],
            ])->get($url);
            $path = $documentRoot . "/testver.php";

            $response = $this->putJson("/api/users/{$username}/files/put-contents", [
                'path' => $path,
                'contents' => '<?php echo phpversion();',
            ]);
            $url .= '/testver.php';

            // there may be a delay while switching php versions
            sleep(5);

            $response = Http::withOptions([
                'verify' => false,
                'curl' => [
                    CURLOPT_RESOLVE => ["{$domainName}:443:" . $ip],
                ],
            ])->get($url);

            $this->assertEquals(200, $response->status(), "URL: " . $url);
            $match = Str::startsWith($response->body(), $version);
            // dump("domain {$domainName} PHP version " . $response->body() . " ==? " . $version);
            $this->assertTrue($match, "PHP version mismatch: " . $response->body() . " != " . $version);
        }
    }
}
