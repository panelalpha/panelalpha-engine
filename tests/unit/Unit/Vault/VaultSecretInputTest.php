<?php

namespace Tests\Unit\Vault;

use App\Http\Requests\VaultSecretRequest;
use Illuminate\Testing\TestResponse;

/**
 * The paste field's rules, and that a value they reject never reaches the session.
 *
 * On a failed validation Laravel redirects back with the input flashed as old
 * input, and the file session driver writes that in plain text under
 * `storage/framework/sessions`. A pasted key or certificate bundle over the
 * length limit would leave a copy there -- the one copy the vault exists to
 * prevent.
 */
class VaultSecretInputTest extends VaultTestCase
{
    /** What a browser posts beside the secret. */
    private const CSRF_FIELD = ['_token' => 'csrf-token-value'];

    private const LIMIT = VaultSecretRequest::MAX_LENGTH;

    public function test_a_secret_at_the_length_limit_is_saved(): void
    {
        [$entry, $ref] = $this->entry(['type' => 'some_future_secret']);
        $secret = str_repeat('a', self::LIMIT);

        $response = $this->post('/vault/' . $ref, self::CSRF_FIELD + ['secret' => $secret]);

        $response->assertOk();
        $this->assertSame($secret, $entry->refresh()->revealSecret());
    }

    public function test_a_secret_over_the_limit_is_not_flashed_to_the_session(): void
    {
        [, $ref] = $this->entry(['type' => 'some_future_secret']);

        $response = $this->post('/vault/' . $ref, self::CSRF_FIELD + ['secret' => str_repeat('a', self::LIMIT + 1)]);

        $this->assertRejectedWithoutFlashingTheSecret($response);
    }

    public function test_an_empty_paste_is_not_flashed_to_the_session(): void
    {
        [, $ref] = $this->entry(['type' => 'some_future_secret']);

        $response = $this->post('/vault/' . $ref, self::CSRF_FIELD + ['secret' => '']);

        $this->assertRejectedWithoutFlashingTheSecret($response);
    }

    /**
     * The other field must survive in the old input, or an empty flash would
     * make the assertion pass without proving anything.
     */
    private function assertRejectedWithoutFlashingTheSecret(TestResponse $response): void
    {
        $response->assertSessionHasErrors('secret');

        $old = session()->getOldInput();
        $this->assertArrayNotHasKey('secret', $old, 'The rejected secret is in the session.');
        $this->assertArrayHasKey('_token', $old, 'Nothing was flashed, so the check proves nothing.');
    }
}
