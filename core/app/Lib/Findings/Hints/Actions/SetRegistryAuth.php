<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\Hints\Hint;

/** Credentials for the private registry a base image is pulled from. */
final class SetRegistryAuth extends Hint
{
    public function resolves(): array
    {
        return ['base-image-unauthorized'];
    }

    public function rest(): string
    {
        return 'PUT /projects/{username}/settings/{key}';
    }

    public function params(): array
    {
        return ['key' => 'registry-auth', 'value' => null];
    }

    public function cli(): string
    {
        return 'project:settings:set {key} --project={username} --value={value}';
    }
}
