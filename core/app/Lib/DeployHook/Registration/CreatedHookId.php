<?php

namespace App\Lib\DeployHook\Registration;

use App\Lib\DeployHook\ProviderRegistrationRefused;

/** The `id` a provider answers a created webhook with, for the providers that number their hooks. */
trait CreatedHookId
{
    /**
     * @param  array<string, mixed>  $body
     *
     * @throws ProviderRegistrationRefused
     */
    private function createdHookId(array $body): string
    {
        $id = $body['id'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            throw ProviderRegistrationRefused::unreachable($this->name(), 'the created webhook had no id');
        }

        return (string) $id;
    }
}
