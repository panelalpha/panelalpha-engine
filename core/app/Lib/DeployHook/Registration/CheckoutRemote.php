<?php

namespace App\Lib\DeployHook\Registration;

use App\Models\User;

/**
 * The remote a checkout came from: its repository URL and clone token (the Deploy-managed `git_token` or a Site Git checkout's
 * own), both from the site_git entry the git tools read, so a registration uses exactly the credentials a pull would.
 */
final class CheckoutRemote
{
    public function __construct(
        public readonly string $repoUrl,
        public readonly ?string $token,
    ) {}

    /** The remote of `$pathKey`: the Deploy-managed `git_token`, or a Site Git entry's own token. */
    public static function of(User $user, string $pathKey): self
    {
        $entry = $user->getSiteGit($pathKey) ?? [];
        $token = $entry['token'] ?? null;

        return new self(
            (string) ($entry['repo_url'] ?? ''),
            is_string($token) && $token !== '' ? $token : null,
        );
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }
}
