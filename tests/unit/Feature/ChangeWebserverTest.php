<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 */
class ChangeWebserverTest extends TestCase
{
    public function test_rejects_serial_number_when_not_switching_to_litespeed(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/change-webserver', [
            'new_webserver' => 'nginx',
            'serial_number' => 'ABC-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['serial_number']);
    }

    public function test_rejects_serial_number_for_openlitespeed(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/change-webserver', [
            'new_webserver' => 'openlitespeed',
            'serial_number' => 'ABC-123',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['serial_number']);
    }

    public function test_invalid_litespeed_serial_number_returns_validation_error(): void
    {
        $this->authenticate();
        $response = $this->putJson('/api/system/change-webserver', [
            'new_webserver' => 'litespeed',
            'serial_number' => 'invalid-serial-number',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['serial_number']);
        $response->assertJsonPath(
            'errors.serial_number.0',
            'The LiteSpeed serial number is invalid.'
        );
    }
}
