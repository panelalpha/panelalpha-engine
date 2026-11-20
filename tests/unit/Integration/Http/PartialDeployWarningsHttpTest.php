<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Domains\PublicUrl;
use App\Lib\Findings\Finding;
use App\Models\User;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A partial deploy's warnings as REST, and MCP through it, show them: with a code, the facts and the hints. */
class PartialDeployWarningsHttpTest extends TestCase
{
    use InMemoryDatabase;

    private const DOMAIN = 'pvvk.136-243-229-118.panelalpha.direct';

    private ?string $username = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
    }

    protected function tearDown(): void
    {
        if ($this->username !== null) {
            DeployLogger::deleteUserLogs($this->username);
        }
        parent::tearDown();
    }

    private function partialProject(): User
    {
        $this->username = 'pdw'.bin2hex(random_bytes(4));
        $warning = new Finding(PublicUrl::CERTIFICATE_REFUSED, 'Refused.', ['domain' => self::DOMAIN]);
        $user = $this->makeUser($this->username, [
            'template' => 'dind',
            'deployment_status' => 'partial',
            'deployment_warnings' => [$warning->toArray()],
        ]);
        DeployLogger::start($this->username)->finishPartial([$warning]);

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function expectedHints(): array
    {
        return [
            ['type' => 'rest', 'action' => 'request_certificate', 'method' => 'POST', 'path' => '/projects/{username}/domains/{domain}/request-ssl-cert', 'path_params' => ['username' => $this->username, 'domain' => self::DOMAIN]],
            ['type' => 'mcp', 'action' => 'request_certificate', 'tool' => 'ssl_cert_request', 'arguments' => ['name' => $this->username, 'domain' => self::DOMAIN]],
            ['type' => 'cli', 'action' => 'request_certificate', 'command' => 'pae ssl:project-cert:request '.self::DOMAIN],
            ['type' => 'rest', 'action' => 'check_certificate', 'method' => 'GET', 'path' => '/projects/{username}/domains/{domain}/ssl-check', 'path_params' => ['username' => $this->username, 'domain' => self::DOMAIN]],
            ['type' => 'mcp', 'action' => 'check_certificate', 'tool' => 'ssl_check', 'arguments' => ['name' => $this->username, 'domain' => self::DOMAIN]],
        ];
    }

    public function test_the_deploy_log_lists_the_warnings_and_leaves_error_empty(): void
    {
        $user = $this->partialProject();

        $response = $this->getJson("/api/projects/{$user->username}/deploy-log")->assertOk();

        $response->assertJsonPath('data.status', DeployLogger::STATUS_PARTIAL);
        $response->assertJsonPath('data.error', null);
        $this->assertSame([[
            'code' => PublicUrl::CERTIFICATE_REFUSED,
            'message' => 'Refused.',
            'domain' => self::DOMAIN,
            'hints' => $this->expectedHints(),
        ]], $response->json('data.warnings'));
    }

    public function test_the_project_shows_its_stored_warnings_with_hints(): void
    {
        $user = $this->partialProject();

        $response = $this->getJson("/api/projects/{$user->username}")->assertOk();

        $response->assertJsonPath('data.details.deployment_warnings.0.code', PublicUrl::CERTIFICATE_REFUSED);
        $this->assertSame($this->expectedHints(), $response->json('data.details.deployment_warnings.0.hints'));
    }

    public function test_the_deploy_list_shows_them_too(): void
    {
        $user = $this->partialProject();

        $response = $this->getJson("/api/projects/{$user->username}/deploys")->assertOk();

        $response->assertJsonPath('data.0.error', null);
        $this->assertSame($this->expectedHints(), $response->json('data.0.warnings.0.hints'));
    }

    public function test_a_failed_deploy_says_its_code_and_what_resolves_it(): void
    {
        $this->username = 'pdf'.bin2hex(random_bytes(4));
        $this->makeUser($this->username, ['template' => 'dind']);
        DeployLogger::start($this->username)->finishFailed(new Finding('out-of-memory', 'The build ran out of memory.'));

        $response = $this->getJson("/api/projects/{$this->username}/deploy-log")->assertOk();

        $response->assertJsonPath('data.status', DeployLogger::STATUS_FAILED);
        $response->assertJsonPath('data.error', 'The build ran out of memory.');
        $response->assertJsonPath('data.failure.code', 'out-of-memory');
        $this->assertSame(
            ['raise_memory_limit', 'read_deploy_log', 'redeploy'],
            array_values(array_unique(array_column($response->json('data.failure.hints'), 'action'))),
        );
        $this->assertSame('out-of-memory', $this->getJson("/api/projects/{$this->username}/deploys")->json('data.0.failure.code'));
    }
}
