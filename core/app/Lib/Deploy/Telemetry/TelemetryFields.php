<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\MetadataRegistry;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\System;
use App\System\Privileged;
use Illuminate\Support\Facades\Log;

/**
 * The facts a report is made of, each gathered so that its absence costs nothing: the account may be rolled back
 * moments later, and a report missing one field is worth writing where no report at all is not.
 */
final class TelemetryFields
{
    private const PROJECT_DIR = '/project';

    private const DIND_TEMPLATE = 'dind';

    /** The account record named the repository; the disk was not asked. */
    public const REPO_FROM_ACCOUNT = 'account';

    /** Nothing was recorded and the remote was read off the checkout. */
    public const REPO_FROM_CHECKOUT = 'checkout';

    /** Seconds one git read may take. Local, on a tree that is already there. */
    private const GIT_TIMEOUT = 15;

    private readonly ?User $user;

    /**
     * Read once and kept: three fields answer out of it, and the tree may be
     * deleted between the first of them and the last.
     *
     * @var ?array<string, mixed>
     */
    private ?array $checkout = null;

    public function __construct(public readonly string $username)
    {
        $this->user = self::safely(fn (): ?User => User::findByUsername($username), null);
    }

    /**
     * Evaluate one piece of a report, falling back rather than propagating.
     *
     * @template T
     *
     * @param  callable(): T  $value
     * @param  T  $default
     * @return T
     */
    public static function safely(callable $value, mixed $default): mixed
    {
        try {
            return $value();
        } catch (\Throwable $e) {
            Log::debug('Telemetry field unavailable: '.$e->getMessage());

            return $default;
        }
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * The repository this project deploys from: the URL the account record asked for, else the checkout's remote,
     * as a `POST /git/connect` site or an archive carrying a `.git` deploys from one the record never heard of.
     */
    public function repoUrl(): ?string
    {
        $stored = self::safely(fn (): ?string => $this->user?->getGitRepo(), null);
        if (is_string($stored) && trim($stored) !== '') {
            return $stored;
        }

        $remote = $this->checkout()['remote'] ?? null;

        return is_string($remote) && trim($remote) !== '' ? $remote : null;
    }

    /** Which of the two answered, so a report can say how sure it is. */
    public function repoSource(): ?string
    {
        if ($this->repoUrl() === null) {
            return null;
        }

        $stored = self::safely(fn (): ?string => $this->user?->getGitRepo(), null);

        return is_string($stored) && trim($stored) !== ''
            ? self::REPO_FROM_ACCOUNT
            : self::REPO_FROM_CHECKOUT;
    }

    /**
     * Whether the repository is the customer's to keep private: a stored token says so, and so does any URL that
     * is not plain HTTPS without credentials, as a stranger could not clone it.
     */
    public function repoIsPrivate(): bool
    {
        return self::isPrivateRepo(
            $this->repoUrl(),
            self::safely(fn (): ?string => $this->user?->getGitToken(), null)
                ?? self::safely(fn (): ?string => $this->siteToken(), null)
        );
    }

    /** The rule itself, so it can be read and tested without an account. */
    public static function isPrivateRepo(?string $url, ?string $token): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        if (is_string($token) && trim($token) !== '') {
            return true;
        }

        return ! GitUrl::isHttpsWithoutCredentials($url);
    }

    /**
     * What the checkout in ~/project says about itself.
     *
     * @return array<string, mixed> {@see CheckoutFacts::read()}
     */
    public function checkout(): array
    {
        if ($this->checkout === null) {
            $this->checkout = self::safely(fn (): array => $this->readCheckout(), ['present' => false]);
        }

        return $this->checkout;
    }

    /**
     * Git is asked only about a directory that hides its contents or holds a `.git` (a file in a worktree), so the
     * commonest account, with no repository, costs no process.
     *
     * @return array<string, mixed>
     */
    private function readCheckout(): array
    {
        $dir = $this->projectDir();
        if (! is_dir($dir) || (is_readable($dir) && ! file_exists($dir.'/.git'))) {
            return ['present' => false];
        }

        return CheckoutFacts::read($dir, $this->gitRunner());
    }

    /**
     * A credential stored against the checkout (`site_git`, from connecting a repository afterwards) rather than
     * against the account; both mean somebody had to authenticate.
     */
    private function siteToken(): ?string
    {
        $entry = $this->user?->getSiteGit(trim(self::PROJECT_DIR, '/'));

        return is_string($entry['token'] ?? null) && $entry['token'] !== '' ? $entry['token'] : null;
    }

    /**
     * Runs a {@see CheckoutFacts} git argv as the account. Null on failure or for an account without a uid and gid.
     *
     * @return callable(list<string>): ?string
     */
    private function gitRunner(): callable
    {
        $system = new System;
        $ids = self::safely(fn (): ?array => $this->user?->linuxIds(), null);

        return static function (array $argv) use ($system, $ids): ?string {
            $at = array_search('-C', $argv, true);
            if ($ids === null || ! is_int($at) || ! isset($argv[$at + 1])) {
                return null;
            }
            try {
                $git = Privileged::account()->gitOnAccountCheckout($ids[0], $ids[1], $argv[$at + 1], array_slice($argv, $at + 2));

                return $system->exec($git, [], self::GIT_TIMEOUT);
            } catch (\Throwable $e) {
                return null;
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function accountDetails(): array
    {
        return self::safely(fn (): array => $this->user?->getDetails() ?? [], []);
    }

    /** The classic `default` template has none of the instrumentation this reads. */
    public function isDindAccount(): bool
    {
        return $this->user !== null
            && self::safely(fn (): bool => $this->user->getTemplate() === self::DIND_TEMPLATE, false);
    }

    public function projectDir(): string
    {
        return (new System)->projectHomeDirPath($this->username).self::PROJECT_DIR;
    }

    /**
     * Root manifest filenames in the account's project directory — the same
     * listing detection itself ran on, so a report shows what the detector saw.
     *
     * @return list<string>
     */
    public function manifests(): array
    {
        return self::safely(
            fn (): array => array_keys(ProjectContext::listRootFiles($this->projectDir())),
            []
        );
    }

    /**
     * The recipe ids that could have deployed this project, best first, read off the tree at the event: next to the
     * one used, they say whether a better recipe existed, such as a Dockerfile the engine outranked.
     *
     * @return list<string>
     */
    public function candidates(): array
    {
        return self::safely(function (): array {
            $context = ProjectContext::at($this->projectDir(), $this->repoUrl());

            return array_column(PlatformCandidates::forContext($context), 'id');
        }, []);
    }

    /**
     * What the package files say this application is, primary ecosystem first, by detection's own readers.
     * {@see AppFacts} decides which parts may be transmitted; this only gathers.
     *
     * @param  ?string  $runtime  the ecosystem the deploy chose to build, when known
     * @return list<AppPackage>
     */
    public function packages(?string $runtime = null): array
    {
        return self::safely(function () use ($runtime): array {
            $context = ProjectContext::at($this->projectDir());
            $packages = MetadataRegistry::read($context);
            $primary = MetadataRegistry::primary($packages, $runtime);
            if ($primary === null) {
                return [];
            }

            return array_merge([$primary], array_values(array_filter(
                $packages,
                static fn (AppPackage $package): bool => $package !== $primary
            )));
        }, []);
    }

    /**
     * The real hostnames this project answers on, unredacted: the account's domain first, marked primary and main,
     * then each `domains` row with its tunnels and aliases, capped last so a long alias list never drops the primary.
     *
     * @return list<array{domain: string, primary?: true, type?: string, alias?: true, tunnel?: string}>
     */
    public function domains(): array
    {
        if ($this->user === null) {
            return [];
        }

        $found = [];
        self::addHostname($found, (string) self::safely(fn (): string => (string) $this->user->domain, ''), [
            'primary' => true,
            'type' => 'main',
        ]);

        foreach (self::safely(fn (): array => $this->user->domains()->get()->all(), []) as $domain) {
            if (! $domain instanceof Domain) {
                continue;
            }

            self::addDomainHostnames($found, $domain);
        }

        return array_slice(array_values($found), 0, DeployReport::MAX_NAMES);
    }

    /**
     * The name the engine's certificate is issued for and the IPv4 it records: what {@see ServerNames} keeps out of
     * a report.
     *
     * @return list<string>
     */
    public function serverNames(): array
    {
        $names = [];
        foreach (['cert_domain', 'default_ipv4'] as $setting) {
            $value = self::safely(fn (): mixed => Setting::get($setting), null);
            if (is_string($value) && trim($value) !== '') {
                $names[] = trim($value);
            }
        }

        return $names;
    }

    /**
     * A `domains` row's own name with its type, then its tunnel hostnames, then its aliases.
     *
     * @param  array<string, array<string, mixed>>  $found
     */
    private static function addDomainHostnames(array &$found, Domain $domain): void
    {
        $facts = [];
        $type = self::safely(fn (): string => (string) $domain->type, '');
        if ($type !== '') {
            $facts['type'] = $type;
        }

        self::addHostname($found, (string) $domain->domain, $facts);

        foreach (self::tunnelHostnames($domain) as $hostname => $provider) {
            self::addHostname($found, $hostname, $provider === '' ? [] : ['tunnel' => $provider]);
        }

        foreach (self::safely(fn (): array => $domain->getAliases(), []) as $alias) {
            if (is_string($alias)) {
                self::addHostname($found, $alias, ['alias' => true]);
            }
        }
    }

    /**
     * First writer wins, and the main domain is written first, so a name that is also a `domains` row
     * keeps its primary marking.
     *
     * @param  array<string, array<string, mixed>>  $found
     * @param  array<string, mixed>  $facts
     */
    private static function addHostname(array &$found, string $name, array $facts): void
    {
        $name = self::normalizeHostname($name);
        if ($name === '') {
            return;
        }

        if (! isset($found[$name])) {
            $found[$name] = ['domain' => $name] + $facts;
        }
    }

    /**
     * The public hostnames a domain's tunnels registered, read through the relation so a domain whose rows
     * are missing costs nothing.
     *
     * @return array<string, string> hostname => provider, provider '' when unnamed
     */
    private static function tunnelHostnames(Domain $domain): array
    {
        return self::safely(function () use ($domain): array {
            $names = [];
            foreach ($domain->tunnels()->get() as $tunnel) {
                $hostname = trim((string) ($tunnel->hostname ?? ''));
                if ($hostname !== '') {
                    $names[$hostname] = trim((string) ($tunnel->provider ?? ''));
                }
            }

            return $names;
        }, []);
    }

    /**
     * A hostname as compared: lowercase, no trailing dot, scheme or path; '' when it is not a name, or holds
     * anything outside a hostname's alphabet, so a credential pasted into a domain row is never relayed.
     */
    public static function normalizeHostname(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $name);
        $name = explode('/', $name)[0];
        $name = rtrim($name, '.');

        if ($name === '' || str_contains($name, '@') || ! str_contains($name, '.') || str_contains($name, ' ')) {
            return '';
        }

        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name) === 1 ? $name : '';
    }

    /**
     * @return list<string>
     */
    public function logTail(DeployLogger $logger, int $lines): array
    {
        return self::safely(fn (): array => array_column($logger->tail($lines), 'msg'), []);
    }
}
