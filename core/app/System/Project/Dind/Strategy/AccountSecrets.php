<?php

namespace App\System\Project\Dind\Strategy;

use App\Lib\Deploy\Compose\ComposeEnvironment;
use App\System\Project\Dind as DindProject;

/**
 * Secrets a generated deploy needs that nobody supplied.
 *
 * Derived rather than random, and that is the whole design: a Rails
 * secret_key_base regenerated on each rebuild logs every session out, and a
 * compose password regenerated on each redeploy locks the app out of its own
 * database. Deriving from the engine app key keeps them stable across
 * rebuilds while remaining unguessable from the username alone, and keying by
 * purpose keeps one leaking from exposing the rest.
 */
class AccountSecrets
{
    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Environment the customer set for this deploy. A key they supplied
     * themselves outranks anything hosting would generate.
     *
     * @return array<string, string>
     */
    public function userEnvVars(): array
    {
        $vars = $this->dind->userModel()->getDetails()['env_vars'] ?? [];

        return is_array($vars) ? $vars : [];
    }

    /**
     * Stable per-account secret. Derived from the engine app key so it is not
     * guessable from the username alone, and deterministic so a rebuild does
     * not log every session out.
     */
    public function generatedSecretKeyBase(): string
    {
        return $this->for('rails-secret-key-base');
    }

    /**
     * The app's own per-account secret ({@see ComposeEnvironment::INSTANCE_SECRET}),
     * for a recipe to salt what the app would otherwise take from `/app`.
     */
    public function instanceSecret(): string
    {
        return $this->for('instance-secret');
    }

    /**
     * @param string $purpose keeps secrets for different uses independent of
     *        each other, so one leaking does not expose the rest
     */
    public function for(string $purpose): string
    {
        return hash_hmac(
            'sha256',
            $purpose . ':' . $this->dind->userModel()->username,
            (string) config('app.key')
        );
    }
}
