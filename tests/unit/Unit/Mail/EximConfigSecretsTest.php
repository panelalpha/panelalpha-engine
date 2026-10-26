<?php

namespace Tests\Unit\Mail;

use App\Lib\Mail\EximConfigSecrets;
use PHPUnit\Framework\TestCase;

class EximConfigSecretsTest extends TestCase
{
    public function test_a_read_hides_every_secret_and_says_which_are_stored(): void
    {
        $masked = EximConfigSecrets::masked([
            'smtp_host' => 'smtp.example.com',
            'smtp_username' => 'mailer',
            'smtp_password' => 'hunter2',
            'sendgrid_api_token' => '',
        ]);

        $this->assertSame('smtp.example.com', $masked['smtp_host']);
        $this->assertSame('mailer', $masked['smtp_username']);
        foreach (EximConfigSecrets::HIDDEN_FIELDS as $field) {
            $this->assertNull($masked[$field], $field);
        }
        $this->assertTrue($masked['smtp_password_set']);
        $this->assertFalse($masked['sendgrid_api_token_set']);
        $this->assertFalse($masked['mailchannels_password_set']);
    }

    public function test_a_secret_sent_empty_is_dropped_so_the_stored_one_is_kept(): void
    {
        $kept = EximConfigSecrets::withoutEmpty([
            'smtp_password' => null,
            'sendgrid_api_token' => '',
            'mailchannels_password' => 'new-secret',
            'smtp_host' => '',
        ]);

        $this->assertSame(['mailchannels_password' => 'new-secret', 'smtp_host' => ''], $kept);
    }
}
