<?php

namespace Tests\Unit\WordpressMigration;

use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The full schema in sqlite, a public engine IPv4 and a configured Connect,
 * for the tests that need projects, domains and tunnels beside the import record.
 */
abstract class ImportTargetTestCase extends TestCase
{
    use InMemoryDatabase;

    protected static function engineIpv4(): string
    {
        return TestNetwork::public(7);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:' . base64_encode(str_repeat('k', 32)),
            'connect.url' => 'https://connect.test',
        ]);
        $this->app->forgetInstance('encrypter');
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => self::engineIpv4()]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    protected function withoutConnect(): void
    {
        config(['connect.url' => '']);
    }

    /**
     * @param array<string, mixed> $details
     */
    protected function project(string $username, string $domain, array $details = []): Domain
    {
        $user = $this->makeUser($username, $details, $domain);

        return $this->makeMainDomain($user, $domain);
    }

    protected function user(string $username): User
    {
        return User::findByUsernameOrFail($username);
    }
}
