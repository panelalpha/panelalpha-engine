<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\Attributes\SetsCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 * @depends Tests\Feature\UserDomainTest::test_create_domain
 */
class DomainsAliasTest extends TestCase
{
    #[SetsCache('domain_alias')]
    public function test_add_alias_to_domain(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $this->authenticate();

        // Get current domain details so we can merge new alias in
        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}");
        $response->assertStatus(200);

        $details = $response->json('data.details');
        assert(is_array($details));

        $aliasName = strtolower('alias' . Str::random(5) . '.test');
        $aliases = $details['aliases'] ?? [];
        assert(is_array($aliases));
        $aliases[] = $aliasName;

        $payload = array_merge($details, ['aliases' => $aliases]);
        $putResponse = $this->putJson("/api/users/{$username}/domains/{$domainName}", $payload);
        $putResponse->assertStatus(200);

        $this->setCache('domain_alias', $aliasName);
    }

    public function test_alias_in_domain_details(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $aliasName = $this->getCacheAsString('domain_alias');
        $this->authenticate();

        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}");
        $response->assertStatus(200);

        $aliases = $response->json('data.details.aliases');
        $this->assertIsArray($aliases);
        $this->assertContains($aliasName, $aliases, "Alias '{$aliasName}' not found in domain details");
    }

    public function test_reject_duplicate_alias(): void
    {
        $username = $this->getCacheAsString('user.username');
        $domainName = $this->getCacheAsString('domain.domain');
        $aliasName = $this->getCacheAsString('domain_alias');
        $this->authenticate();

        // Fetch current details and attempt to add the same alias again
        $response = $this->getJson("/api/users/{$username}/domains/{$domainName}");
        $response->assertStatus(200);

        $details = $response->json('data.details');
        assert(is_array($details));

        $aliases = $details['aliases'] ?? [];
        assert(is_array($aliases));
        $aliases[] = $aliasName; // duplicate

        $payload = array_merge($details, ['aliases' => $aliases]);
        $putResponse = $this->putJson("/api/users/{$username}/domains/{$domainName}", $payload);

        // Either rejected or deduplicated — in both cases only one instance should exist
        if ($putResponse->getStatusCode() === 200) {
            $updatedAliasesRaw = $putResponse->json('data.details.aliases');
            assert(is_array($updatedAliasesRaw));
            /** @var array<int, string> $updatedAliases */
            $updatedAliases = array_filter($updatedAliasesRaw, 'is_string');
            $occurrences = array_count_values($updatedAliases)[$aliasName] ?? 0;
            $this->assertLessThanOrEqual(1, $occurrences,
                "Alias '{$aliasName}' appears more than once after duplicate add"
            );
        } else {
            $this->assertContains($putResponse->getStatusCode(), [400, 409, 422]);
        }
    }
}
