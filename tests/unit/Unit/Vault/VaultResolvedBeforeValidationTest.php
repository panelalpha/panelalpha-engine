<?php

namespace Tests\Unit\Vault;

use App\Http\Controllers\User\AppUserController;
use App\Http\Requests\EximConfigUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** References are read before the field's rules run, so the rules check the secret. */
class VaultResolvedBeforeValidationTest extends VaultTestCase
{
    public function test_a_short_reference_is_read_not_refused_by_min_8(): void
    {
        // `vault:9` is 7 characters: validated first, min:8 refused it.
        $request = Request::create('/api/projects/shop/app/users/1/reset-password', 'PUT', ['password' => 'vault:9']);

        try {
            (new AppUserController())->resetPassword('shop', '1', $request);
            $this->fail('expected a 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No vault entry', $e->errors()['password'][0]);
        }
    }

    public function test_a_mail_secret_from_the_vault_is_checked_by_exim_secret(): void
    {
        [$entry] = $this->globalEntry('smtp_password', "pass\n*:evil:x");
        $request = EximConfigUpdateRequest::create('/api/system/exim-config', 'PUT', ['smtp_password' => $entry->reference()]);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));

        try {
            $request->validateResolved();
            $this->fail('expected a 422');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('line break', $e->errors()['smtp_password'][0]);
        }
    }
}
