<?php

namespace App\Lib\DeployHook;

/**
 * A webhook registration the git provider did not accept, or could not be
 * asked for. Carried out of the library rather than rendered there, because
 * only the HTTP boundary knows a refusal is a 422 body and an unreachable
 * provider a 502.
 */
final class ProviderRegistrationRefused extends \RuntimeException
{
    private function __construct(string $message, public readonly int $httpStatus, public readonly ?string $problemCode)
    {
        parent::__construct($message);
    }

    public static function tokenMissing(): self
    {
        return new self(
            'The checkout has no git token, so the engine cannot register a webhook at the provider. '
                .'Connect the checkout with a token, or register the URL in the git host yourself.',
            422,
            'provider_token_missing',
        );
    }

    public static function permissionDenied(string $provider, int $status): self
    {
        return new self(
            "The git provider refused the webhook registration: {$provider} answered {$status}. "
                .'The token needs permission to manage repository webhooks '
                .'(GitHub admin:repo_hook on the repository, GitLab maintainer or owner, Bitbucket admin).',
            422,
            'provider_permission_denied',
        );
    }

    public static function unreachable(string $provider, string $reason): self
    {
        return new self("The webhook registration at {$provider} could not be completed: {$reason}.", 502, null);
    }
}
