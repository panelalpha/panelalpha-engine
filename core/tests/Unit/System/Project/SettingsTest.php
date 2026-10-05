<?php

namespace Tests\Unit\System\Project;

use App\Models\User as ModelsUser;
use App\System;
use App\System\Project;
use App\System\Project\Settings;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    private string $coreAppRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->coreAppRoot = dirname(__DIR__, 4) . '/app';
    }

    public function test_redact_keeps_prefix_and_suffix(): void
    {
        $redacted = Settings::redact('abcdefghijklmnop');
        $this->assertStringStartsWith('abcd', $redacted);
        $this->assertStringEndsWith('mnop', $redacted);
        $this->assertStringContainsString('*', $redacted);
    }

    public function test_unknown_key_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Settings::assertKnownKey('not-a-real-key');
    }

    public function test_cloudflare_token_is_secret(): void
    {
        $this->assertTrue(Settings::isSecret('cloudflare-api-token'));
        $this->assertTrue(Settings::isSecret('Cloudflare-API-Token'));
    }

    public function test_get_redacts_secret_and_reports_set(): void
    {
        $user = $this->userWithToken('abcdefghijklmnop');

        $row = (new Settings($this->projectFor($user)))->get(Settings::KEY_CLOUDFLARE_API_TOKEN);

        $this->assertSame(Settings::KEY_CLOUDFLARE_API_TOKEN, $row['key']);
        $this->assertSame(Settings::redact('abcdefghijklmnop'), $row['value']);
        $this->assertTrue($row['set']);
        $this->assertTrue($row['secret']);
    }

    public function test_get_reports_unset_secret(): void
    {
        $user = $this->userWithToken(null);

        $row = (new Settings($this->projectFor($user)))->get(Settings::KEY_CLOUDFLARE_API_TOKEN);

        $this->assertNull($row['value']);
        $this->assertFalse($row['set']);
        $this->assertTrue($row['secret']);
    }

    public function test_list_returns_every_known_key(): void
    {
        $user = $this->userWithToken(null);

        $rows = (new Settings($this->projectFor($user)))->list();

        $this->assertCount(count(Settings::KEYS), $rows);
        $this->assertSame(Settings::KEYS, array_column($rows, 'key'));
    }

    public function test_get_reports_empty_string_as_unset(): void
    {
        $user = $this->userWithToken('');

        $row = (new Settings($this->projectFor($user)))->get(Settings::KEY_CLOUDFLARE_API_TOKEN);

        $this->assertFalse($row['set']);
    }

    public function test_registry_auth_is_a_secret_and_read_back_redacted(): void
    {
        $user = $this->userWithToken(null);
        $user->method('getRegistryAuth')->willReturn('ghcr.io acme ghp_abcdefghijklmnop');

        $row = (new Settings($this->projectFor($user)))->get(Settings::KEY_REGISTRY_AUTH);

        $this->assertTrue(Settings::isSecret(Settings::KEY_REGISTRY_AUTH));
        $this->assertTrue($row['set']);
        $this->assertStringNotContainsString('ghp_abcdefghijklmnop', (string) $row['value']);
    }

    public function test_registry_auth_is_stored_once_it_parses(): void
    {
        $user = $this->userWithToken(null);
        $user->expects($this->once())->method('setDetails')
            ->with(['registry_auth' => "ghcr.io acme tok1\nregistry.example.com:5000 bot tok2"]);
        $user->expects($this->once())->method('save');

        $result = (new Settings($this->projectFor($user)))->set(
            Settings::KEY_REGISTRY_AUTH,
            "  ghcr.io acme tok1\nregistry.example.com:5000 bot tok2\n"
        );

        $this->assertStringContainsString('ghcr.io, registry.example.com:5000', (string) $result['message']);
    }

    public function test_registry_auth_refuses_a_line_it_cannot_read_and_stores_nothing(): void
    {
        $user = $this->userWithToken(null);
        $user->expects($this->never())->method('setDetails');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line 2');
        (new Settings($this->projectFor($user)))->set(Settings::KEY_REGISTRY_AUTH, "ghcr.io acme tok\nghcr.io-only-a-host");
    }

    public function test_persist_paths_are_absolute_and_normalised(): void
    {
        $this->assertSame(
            ['/app/storage', '/app/public/uploads', '/app/.cache'],
            Settings::persistPaths(' /app/storage/ , //app/public//uploads,,/app/.cache,/app/storage')
        );
    }

    public function test_persist_paths_refuse_what_a_volume_cannot_be_mounted_on(): void
    {
        foreach (['app/uploads', '/', '/app/../etc', '/app/up loads', '/app/x:/y', ''] as $bad) {
            try {
                Settings::persistPaths($bad);
                $this->fail("'{$bad}' was accepted");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_persist_paths_are_stored_as_a_list_and_read_back_as_set(): void
    {
        $user = $this->userWithToken(null);
        $user->expects($this->once())->method('setDetails')->with(['persist_paths' => ['/app/uploads', '/data']]);
        $user->method('getPersistPaths')->willReturn(['/app/uploads', '/data']);
        $settings = new Settings($this->projectFor($user));

        $settings->set(Settings::KEY_PERSIST_PATHS, '/app/uploads,/data');
        $row = $settings->get(Settings::KEY_PERSIST_PATHS);

        $this->assertSame('/app/uploads,/data', $row['value']);
        $this->assertFalse($row['secret']);
    }

    public function test_project_aggregate_declares_settings(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project.php');
        $this->assertStringContainsString('function settings(): Settings', $source);
        $this->assertStringContainsString('new Settings($this)', $source);
    }

    public function test_dind_does_not_wire_settings_collaborator(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Dind.php');
        $this->assertStringNotContainsString('function settings(): Settings', $source);
        $this->assertStringNotContainsString('settingsCollaborator', $source);
        $this->assertStringContainsString('implements DeployableDindProject, Runtime', $source);
    }

    public function test_dind_implements_runtime_not_project_subclass(): void
    {
        $source = file_get_contents($this->coreAppRoot . '/System/Project/Dind.php');
        $this->assertStringContainsString('implements DeployableDindProject, Runtime', $source);
        $this->assertStringNotContainsString('extends AbstractProject', $source);
        $this->assertStringNotContainsString('use StackImplementationCommon', $source);
    }

    public function test_nested_project_interface_removed(): void
    {
        $this->assertFileDoesNotExist($this->coreAppRoot . '/System/Project/Project.php');
        $this->assertFileDoesNotExist($this->coreAppRoot . '/System/Project/AbstractProject.php');
    }

    private function userWithToken(?string $token): ModelsUser
    {
        $user = $this->createMock(ModelsUser::class);
        $user->username = 'alice';
        $user->method('hasGitProject')->willReturn(true);
        $user->method('getCloudflareApiToken')->willReturn($token);
        // What Settings reports: the stored value, never a resolved global.
        $user->method('getCloudflareApiToken')->willReturn($token);

        return $user;
    }

    private function projectFor(ModelsUser $user): Project
    {
        return new Project($this->createMock(System::class), $user);
    }
}
