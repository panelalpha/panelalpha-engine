<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class ModsecTest extends TestCase
{
    public function test_owasp_sqlinjection_rule(): void
    {
        $this->authenticate();

        $username = $this->getCache('user.username');
        assert(is_string($username));

        $domainName = $this->getCache('user.domain');
        assert(is_string($domainName));

        $query = [
            'id' => "1' or '1'='1",
        ];
        $url = "https://{$domainName}/?" . http_build_query($query);

        $response = $this->putJson("/api/modsec/mode", ['mode' => 'off']);
        $response->assertStatus(200);
        sleep(1);

        $ip = Setting::get('default_ipv4');
        assert(is_string($ip));
        $resolveHosts = ["{$domainName}:443:{$ip}"];
        $httpClient = Http::withOptions([
            'verify' => false,
            'curl' => [
                CURLOPT_RESOLVE => $resolveHosts,
            ],
        ]);
        $response = $httpClient->get($url);
        $this->assertEquals(200, $response->status(), "sus url allowed with modsec disabled");

        $response = $this->putJson("/api/modsec/mode", ['mode' => 'on']);
        $response->assertStatus(200);
        $response = $this->putJson("/api/modsec/rulesets/owasp-crs/enable");
        $response->assertStatus(200);
        $response = $this->putJson("/api/modsec/rulesets/owasp-crs/config-files", [
            'enable' => ['REQUEST-942-APPLICATION-ATTACK-SQLI.conf'],
        ]);
        $response->assertStatus(200);
        sleep(1);

        $response = $httpClient->get($url);
        $this->assertEquals(403, $response->status(), "sus url blocked with modsec + owasp rule enabled");
    }
}
