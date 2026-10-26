<?php

namespace App\Lib\DeployHook;

use App\Lib\DeployHook\Registration\BitbucketCloudRegistrar;
use App\Lib\DeployHook\Registration\CheckoutRemote;
use App\Lib\DeployHook\Registration\GithubRegistrar;
use App\Lib\DeployHook\Registration\GitlabRegistrar;
use App\Lib\DeployHook\Registration\WebhookRegistrar;
use App\Lib\DeployHook\Registration\WebhookTarget;

/**
 * Registers a Deploy Hook as a webhook at the git provider its checkout came
 * from, and keeps the provider's id for it so a rotation can update it and a
 * deletion can remove it. Which provider serves a repository is read from the
 * URL's host; a client that names a provider may point this at a self-managed
 * instance, which no host list would recognise.
 */
final class ProviderRegistrar
{
    /** @var list<WebhookRegistrar> */
    private array $registrars;

    /**
     * @param  list<WebhookRegistrar>|null  $registrars
     */
    public function __construct(?array $registrars = null)
    {
        $this->registrars = $registrars ?? [
            new GithubRegistrar,
            new GitlabRegistrar,
            new BitbucketCloudRegistrar,
        ];
    }

    /**
     * Whether a registrar serves this checkout, so a caller can say "the client
     * registers the URL itself" without asking the provider first.
     */
    public function serves(CheckoutRemote $remote, ?string $provider = null): bool
    {
        return $this->target($remote, $provider) !== null;
    }

    /**
     * Create the provider webhook holding `$secret` at `$url`.
     *
     * @return ?ProviderRegistration null when no registrar serves the
     *                               repository, so the client is left to register the URL itself
     *
     * @throws ProviderRegistrationRefused
     */
    public function register(CheckoutRemote $remote, string $url, string $secret, ?string $provider = null): ?ProviderRegistration
    {
        [$registrar, $target] = $this->target($remote, $provider);
        if ($registrar === null || $target === null) {
            return null;
        }
        if (! $remote->hasToken()) {
            throw ProviderRegistrationRefused::tokenMissing();
        }

        $hookId = $registrar->create($target, (string) $remote->token, $url, $secret);

        return new ProviderRegistration($registrar->name(), $hookId, now());
    }

    /**
     * Replace the URL and secret of a webhook registered earlier. False when
     * the provider cannot be addressed anymore -- the checkout was
     * disconnected, or its remote moved -- and the webhook is left as it is.
     *
     * @throws ProviderRegistrationRefused
     */
    public function refresh(CheckoutRemote $remote, string $hookId, string $url, string $secret, ?string $provider): bool
    {
        [$registrar, $target] = $this->target($remote, $provider);
        if ($registrar === null || $target === null || ! $remote->hasToken()) {
            return false;
        }
        $registrar->update($target, (string) $remote->token, $hookId, $url, $secret);

        return true;
    }

    /**
     * Remove a webhook registered earlier. False when the provider cannot be
     * addressed anymore, as in {@see refresh()}.
     *
     * @throws ProviderRegistrationRefused
     */
    public function forget(CheckoutRemote $remote, string $hookId, ?string $provider): bool
    {
        [$registrar, $target] = $this->target($remote, $provider);
        if ($registrar === null || $target === null || ! $remote->hasToken()) {
            return false;
        }
        $registrar->delete($target, (string) $remote->token, $hookId);

        return true;
    }

    /**
     * @return array{0: ?WebhookRegistrar, 1: ?WebhookTarget}
     */
    private function target(CheckoutRemote $remote, ?string $provider): array
    {
        $registrar = $this->registrarForRemote($remote->repoUrl, $provider);

        return [$registrar, $registrar?->address($remote->repoUrl)];
    }

    /**
     * The registrar for a repository URL: by host when the URL names one, else
     * the provider the client named.
     */
    private function registrarForRemote(string $repoUrl, ?string $provider): ?WebhookRegistrar
    {
        $host = strtolower((string) (parse_url(trim($repoUrl), PHP_URL_HOST) ?: ''));
        foreach ($this->registrars as $registrar) {
            if ($host !== '' && in_array($host, $registrar->hosts(), true)) {
                return $registrar;
            }
        }
        if ($provider === null) {
            return null;
        }
        foreach ($this->registrars as $registrar) {
            if ($registrar->name() === $provider) {
                return $registrar;
            }
        }

        return null;
    }
}
