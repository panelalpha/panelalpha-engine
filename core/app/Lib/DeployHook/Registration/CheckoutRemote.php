<?php

namespace App\Lib\DeployHook\Registration;

/**
 * The remote a checkout came from: the repository URL and the token the
 * checkout itself clones with -- the Deploy-managed `git_token`, or a Site Git
 * checkout's own. Both come from the site_git entry the git tools read, so a
 * registration uses exactly the credentials a pull would.
 */
final class CheckoutRemote
{
    public function __construct(
        public readonly string $repoUrl,
        public readonly ?string $token,
    ) {}

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }
}
