<?php

namespace App\Lib\Findings\Hints\Actions;

use App\Lib\Findings\FindingCodes;
use App\Lib\Findings\Hints\Hint;

/** A deploy key kept in the vault, for a project that does not exist yet. */
final class CreateVaultDeployKey extends Hint
{
    public function resolves(): array
    {
        return FindingCodes::VAULT_DEPLOY_KEY;
    }

    public function rest(): string
    {
        return 'POST /vault/deploy-keys';
    }

    public function params(): array
    {
        return ['host' => '{host}'];
    }
}
