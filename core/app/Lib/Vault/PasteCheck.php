<?php

namespace App\Lib\Vault;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Deploy\Source\GitProbeResult;
use App\Lib\Deploy\Source\GitRemoteProbe;
use App\Lib\Deploy\Source\GitRepoInput;
use App\Lib\Deploy\Source\GitTokenInput;
use App\Lib\Deploy\Source\RepositoryAddress;
use App\Models\SecretVaultEntry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Checks a pasted secret before the form stores it.
 *
 * The details to check against (`verify_with`) are given when the link is
 * minted. A git_token goes through the same GitRepoInput, GitTokenInput and
 * GitRemoteProbe that project_create uses; a cloudflare_api_token through the
 * same Cloudflare client calls the tunnel setup makes. The form and the engine
 * can't disagree about a token.
 */
final class PasteCheck
{
    /** @var array<string, list<string>> what `verify_with` may carry, per type */
    public const KEYS = [
        SecretVaultEntry::TYPE_GIT_TOKEN => ['repo_url'],
        SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN => ['hostname'],
    ];

    /** @var \Closure(string): Cloudflare */
    private readonly \Closure $cloudflare;

    /** @param ?\Closure(string): Cloudflare $cloudflare builds the client for a pasted token */
    public function __construct(
        private readonly GitRemoteProbe $probe = new GitRemoteProbe,
        ?\Closure $cloudflare = null,
    ) {
        $this->cloudflare = $cloudflare ?? static fn (string $token): Cloudflare => new Cloudflare($token);
    }

    /**
     * `verify_with` as it will be stored. Called at mint time.
     *
     * @param  ?array<string, mixed>  $verifyWith
     * @return ?array<string, string>
     *
     * @throws ValidationException
     */
    public static function prepare(string $type, ?array $verifyWith): ?array
    {
        if ($verifyWith === null || $verifyWith === []) {
            return null;
        }

        $allowed = self::KEYS[$type] ?? null;
        if ($allowed === null) {
            throw ValidationException::withMessages([
                'verify_with' => "A '{$type}' secret cannot be checked before it is saved; omit verify_with.",
            ]);
        }

        $unknown = array_diff(array_keys($verifyWith), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'verify_with' => 'Unknown key(s) '.implode(', ', $unknown)." for '{$type}'. "
                    .'Expected: '.implode(', ', $allowed).'.',
            ]);
        }

        return $type === SecretVaultEntry::TYPE_GIT_TOKEN
            ? self::prepareGit($verifyWith)
            : self::prepareCloudflare($verifyWith);
    }

    /**
     * The repository is held to the rule project_create applies to git_repo when a token comes with it.
     *
     * @param  array<string, mixed>  $verifyWith
     * @return array<string, string>
     */
    private static function prepareGit(array $verifyWith): array
    {
        $repo = $verifyWith['repo_url'] ?? null;
        if (! is_string($repo) || trim($repo) === '') {
            throw ValidationException::withMessages([
                'verify_with.repo_url' => 'The repository the token must be able to read.',
            ]);
        }

        $problem = GitRepoInput::problem('verify_with.repo_url', $repo, true);
        if ($problem !== null) {
            throw ValidationException::withMessages([$problem['field'] => $problem['message']]);
        }

        return ['repo_url' => GitRepoInput::normalise($repo)];
    }

    /**
     * @param  array<string, mixed>  $verifyWith
     * @return array<string, string>
     */
    private static function prepareCloudflare(array $verifyWith): array
    {
        $host = $verifyWith['hostname'] ?? null;
        $host = is_string($host) ? strtolower(trim($host)) : '';
        if ($host === '' || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw ValidationException::withMessages([
                'verify_with.hostname' => 'The domain the token must be able to manage, e.g. shop.example.com.',
            ]);
        }

        return ['hostname' => $host];
    }

    /**
     * `rejected` is the message to show instead of saving; otherwise
     * `verification` is what to store with the secret (null: nothing was asked).
     *
     * @return array{rejected: ?string, verification: ?array<string, string>}
     */
    public function run(SecretVaultEntry $entry, string $secret): array
    {
        return match ($entry->type) {
            SecretVaultEntry::TYPE_GIT_TOKEN => $this->runGit($entry, $secret),
            SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN => $this->runCloudflare($entry, $secret),
            default => ['rejected' => null, 'verification' => null],
        };
    }

    /**
     * The shape check is project_create's GitAccessToken rule; the address check matters
     * because the probe runs from core, whose network reaches the metadata service.
     *
     * @return array{rejected: ?string, verification: ?array<string, string>}
     */
    private function runGit(SecretVaultEntry $entry, string $secret): array
    {
        $shape = GitTokenInput::problem('secret', $secret);
        if ($shape !== null) {
            return self::rejected($shape['message']);
        }

        $repo = $entry->verify_with['repo_url'] ?? null;
        if (! is_string($repo) || $repo === '') {
            return ['rejected' => null, 'verification' => null];
        }

        $address = RepositoryAddress::problem($repo, 'verify_with.repo_url');
        if ($address !== null) {
            return self::rejected((string) $address['message']);
        }

        $result = $this->probe->check('repo_url', $repo, GitTokenInput::normalise($secret), 'secret');
        if ($result->outcome === GitProbeResult::REFUSED) {
            return self::rejected((string) ($result->problem['message'] ?? 'The repository refused this token.'));
        }

        return ['rejected' => null, 'verification' => self::gitVerification($this->tokenProof($result, $repo), $repo)];
    }

    /**
     * A public repository answers without asking for credentials, so its answer says nothing about the token.
     */
    private function tokenProof(GitProbeResult $result, string $repo): GitProbeResult
    {
        if ($result->outcome === GitProbeResult::VERIFIED
            && $this->probe->check('repo_url', $repo, null)->outcome === GitProbeResult::VERIFIED) {
            return GitProbeResult::unchecked(['message' => 'This repository is public, so the token was not needed '
                .'to read it and could not be tested.']);
        }

        return $result;
    }

    /** @return array<string, string> */
    private static function gitVerification(GitProbeResult $result, string $repo): array
    {
        $verification = [
            'result' => $result->outcome,
            'target' => self::target($repo),
            'checked_at' => Carbon::now()->toIso8601String(),
        ];
        if ($result->outcome === GitProbeResult::UNCHECKED) {
            $verification['reason'] = (string) ($result->problem['message'] ?? 'The engine could not run the check.');
        }

        return $verification;
    }

    /** @return array{rejected: ?string, verification: ?array<string, string>} */
    private function runCloudflare(SecretVaultEntry $entry, string $secret): array
    {
        $host = $entry->verify_with['hostname'] ?? null;
        $client = ($this->cloudflare)(trim($secret));
        $step = 'account';

        try {
            [$account, $zone] = $this->walkTunnelSetup($client, $host, $step);
        } catch (ConnectionException $e) {
            return $this->cloudflareUnchecked($host, 'Cloudflare could not be reached from this engine.');
        } catch (CloudflareException $e) {
            return $this->cloudflareFailure($e, $host, $step);
        }

        $target = 'Cloudflare account '.($account['name'] !== '' ? $account['name'] : $account['id']);
        if (isset($zone)) {
            $target .= ', zone '.$zone['name'];
        }

        return ['rejected' => null, 'verification' => [
            'result' => 'verified',
            'target' => $target,
            'checked_at' => Carbon::now()->toIso8601String(),
        ]];
    }

    /**
     * The tunnel setup's calls, read-only: the account a project's token resolves to, the tunnel
     * list (Tunnel access) and the hostname's zone. $step names the call in flight when one throws.
     *
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function walkTunnelSetup(Cloudflare $client, mixed $host, string &$step): array
    {
        $account = $client->resolveAccount();
        $step = 'tunnels';
        $client->findTunnelByName($account['id'], 'panelalpha-vault-check');
        if (! is_string($host)) {
            return [$account, null];
        }

        $step = 'zone';

        return [$account, $client->findZoneForHostname($host)];
    }

    /** @return array{rejected: ?string, verification: ?array<string, string>} */
    private function cloudflareFailure(CloudflareException $e, mixed $host, string $step): array
    {
        if (self::serverError($e)) {
            return $this->cloudflareUnchecked($host, 'Cloudflare answered with a server error; try again later.');
        }

        return self::rejected(match ($step) {
            'tunnels' => 'This token cannot manage Cloudflare Tunnels. Give it Account > Cloudflare Tunnel > Edit. '
                .'('.$e->getMessage().')',
            default => $e->getMessage(),
        });
    }

    /** @return array{rejected: string, verification: null} */
    private static function rejected(string $message): array
    {
        return ['rejected' => $message, 'verification' => null];
    }

    /** Cloudflare's own failure somewhere down the chain, not a verdict on the token. */
    private static function serverError(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RequestException && $cause->response->serverError()) {
                return true;
            }
        }

        return false;
    }

    /** @return array{rejected: null, verification: array<string, string>} */
    private function cloudflareUnchecked(?string $host, string $reason): array
    {
        return ['rejected' => null, 'verification' => [
            'result' => 'unchecked',
            'target' => is_string($host) ? 'Cloudflare, zone for '.$host : 'Cloudflare',
            'checked_at' => Carbon::now()->toIso8601String(),
            'reason' => $reason,
        ]];
    }

    /** What the form says the paste will be tried against, or null for no check. */
    public static function describe(SecretVaultEntry $entry): ?string
    {
        return match ($entry->type) {
            SecretVaultEntry::TYPE_GIT_TOKEN => is_string($repo = $entry->verify_with['repo_url'] ?? null)
                ? self::target($repo) : null,
            SecretVaultEntry::TYPE_CLOUDFLARE_API_TOKEN => is_string($host = $entry->verify_with['hostname'] ?? null)
                ? "your Cloudflare account and the zone for {$host}" : 'your Cloudflare account',
            default => null,
        };
    }

    /** `github.com/acme/shop`: what the form and the listing show. */
    public static function target(string $repoUrl): string
    {
        $parts = parse_url($repoUrl);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $path = is_array($parts) ? trim((string) ($parts['path'] ?? ''), '/') : '';

        return $host === '' ? $repoUrl : $host.'/'.preg_replace('/\.git$/', '', $path);
    }
}
