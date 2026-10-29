<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\Authenticate;
use App\Models\Setting;
use App\System;
use App\System\Services\Exim;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * Reading the mail settings says whether a password is stored, never what it is,
 * and that answer sent back to the save keeps what is stored.
 */
class EximConfigReadTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        $this->withoutMiddleware(Authenticate::class);
        Setting::set('exim', json_encode([
            'smarthost_provider' => 'smtp',
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'mailer',
            'smtp_password' => 'hunter2',
            'mailchannels_password' => 'mc-secret',
        ]));
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_stored_password_is_answered_as_null_and_flagged_as_set(): void
    {
        $data = $this->getJson('/api/system/exim-config')->assertOk()->json('data');

        $this->assertNull($data['smtp_password']);
        $this->assertTrue($data['smtp_password_set']);
        $this->assertNull($data['mailchannels_password']);
        $this->assertTrue($data['mailchannels_password_set']);
    }

    public function test_a_secret_that_was_never_stored_is_flagged_as_not_set(): void
    {
        $data = $this->getJson('/api/system/exim-config')->json('data');

        $this->assertNull($data['sendgrid_api_token']);
        $this->assertFalse($data['sendgrid_api_token_set']);
        $this->assertNull($data['amazon_ses_smtp_password']);
        $this->assertFalse($data['amazon_ses_smtp_password_set']);
    }

    public function test_no_stored_secret_appears_anywhere_in_the_answer(): void
    {
        $body = $this->getJson('/api/system/exim-config')->getContent();

        $this->assertStringNotContainsString('hunter2', (string) $body);
        $this->assertStringNotContainsString('mc-secret', (string) $body);
    }

    public function test_the_read_answer_sent_back_keeps_every_stored_secret(): void
    {
        $this->withoutRebuildingExim();
        $data = $this->getJson('/api/system/exim-config')->json('data');

        $this->putJson('/api/system/exim-config', [...$data, 'smtp_host' => 'mail.example.com'])->assertOk();

        $stored = Setting::getEximConfig();
        $this->assertSame('hunter2', $stored['smtp_password']);
        $this->assertSame('mc-secret', $stored['mailchannels_password']);
        $this->assertSame('mail.example.com', $stored['smtp_host']);
    }

    public function test_clear_secrets_clears_only_the_secrets_it_names(): void
    {
        $this->withoutRebuildingExim();

        $this->putJson('/api/system/exim-config', ['clear_secrets' => ['smtp_password']])->assertOk();

        $stored = Setting::getEximConfig();
        $this->assertSame('', $stored['smtp_password']);
        $this->assertSame('mc-secret', $stored['mailchannels_password']);
    }

    public function test_a_new_password_replaces_the_stored_one(): void
    {
        $this->withoutRebuildingExim();

        $this->putJson('/api/system/exim-config', ['smtp_password' => 'new-secret'])->assertOk();

        $this->assertSame('new-secret', Setting::getEximConfig()['smtp_password']);
    }

    public function test_clear_secrets_names_only_the_hidden_secrets(): void
    {
        $this->withoutRebuildingExim();

        $this->putJson('/api/system/exim-config', ['clear_secrets' => ['smtp_host']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['clear_secrets.0']);
        $this->assertSame('smtp.example.com', Setting::getEximConfig()['smtp_host']);
    }

    private function withoutRebuildingExim(): void
    {
        $system = $this->createStub(System::class);
        $system->method('exim')->willReturn($this->createStub(Exim::class));
        $this->app->instance(System::class, $system);
    }

    public function test_the_other_settings_are_answered_as_stored(): void
    {
        $data = $this->getJson('/api/system/exim-config')->json('data');

        $this->assertSame('smtp.example.com', $data['smtp_host']);
        $this->assertSame('mailer', $data['smtp_username']);
    }
}
