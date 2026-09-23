<?php

namespace App\Lib\Deploy\Telemetry;

use App\System;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\Metadata\MetadataRegistry;
use App\Lib\Deploy\Platform\PlatformCandidates;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Source\GitUrl;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The facts a report is made of, each gathered so that its absence costs
 * nothing.
 *
 * Telemetry runs at the end of a deploy that may have failed *because*
 * something is wrong, and the account it describes is often deleted moments
 * later: the database may be unreachable, the log file gone, the home
 * directory already rolled back. A report missing one field is worth writing;
 * no report at all is not.
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
     * @param callable(): T $value
     * @param T $default
     * @return T
     */
    public static function safely(callable $value, mixed $default): mixed
    {
        try {
            return $value();
        } catch (\Throwable $e) {
            Log::debug('Telemetry field unavailable: ' . $e->getMessage());

            return $default;
        }
    }

    public function user(): ?User
    {
        return $this->user;
    }

    /**
     * The repository this project deploys from.
     *
     * The account record first, because that is the URL a deploy was *asked*
     * for. Where there is none, the checkout is asked instead: a site
     * connected through `POST /git/connect` and an archive that turned out to
     * carry a `.git` both deploy from a repository the account record has
     * never heard of, and reporting nothing for them put every failure they
     * caused into the "no repository" bucket.
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
     * Whether the repository is the customer's to keep private.
     *
     * A stored token is the first answer and always was: the engine was given
     * a credential, so the repository needs one. The URL itself is the second,
     * and it is the one that was missing. Anything that is not plain HTTPS
     * without embedded credentials — an `ssh://`, a `git@host:owner/repo`, a
     * URL with a password in it — cannot be cloned by a stranger, so it is not
     * public, and naming its path in the clear because no token happened to be
     * stored on the account was a disclosure this class could always have
     * avoided. A remote read off the disk is judged by exactly the same rule.
     *
     * A token the account *inherits* from the engine ({@see
     * \App\Lib\Vault\GlobalVault}) counts, deliberately: it is a credential
     * that may be what clones this repository, and the choice here has always
     * been to over-redact rather than name a private path. An engine with a
     * global Git token therefore reports fewer repository URLs, which is the
     * safe direction to be wrong in.
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

        return !GitUrl::isHttpsWithoutCredentials($url);
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
     * The commonest account has no repository at all, and answering that must
     * not cost a process: an archive upload would otherwise pay for a git it
     * was always going to fail. Only a directory that exists and either hides
     * its contents from us or has a `.git` in it is worth asking git about --
     * `.git` is a file rather than a directory in a worktree, so its kind is
     * not checked, only its presence.
     *
     * @return array<string, mixed>
     */
    private function readCheckout(): array
    {
        $dir = $this->projectDir();
        if (!is_dir($dir) || (is_readable($dir) && !file_exists($dir . '/.git'))) {
            return ['present' => false];
        }

        return CheckoutFacts::read($dir, self::gitRunner());
    }

    /**
     * A credential stored against the checkout rather than against the account.
     *
     * `git_repo`/`git_token` is how a project *created* from a repository
     * remembers one; `site_git` is how one connected to a repository
     * afterwards does. Both mean the same thing here — somebody had to
     * authenticate — and only the first was ever consulted.
     */
    private function siteToken(): ?string
    {
        $entry = $this->user?->getSiteGit(trim(self::PROJECT_DIR, '/'));

        return is_string($entry['token'] ?? null) && $entry['token'] !== '' ? $entry['token'] : null;
    }

    /**
     * Runs one git command against a checkout owned by somebody else.
     *
     * `sudo` because core runs as www-data and the tree belongs to the account
     * user; a failure is a null rather than an exception, because a report
     * that lost one field is worth more than no report at all.
     *
     * @return callable(list<string>): ?string
     */
    private static function gitRunner(): callable
    {
        $system = new System();

        return static function (array $argv) use ($system): ?string {
            try {
                return $system->exec(['sudo', ...$argv], [], self::GIT_TIMEOUT);
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
        return (new System())->projectHomeDirPath($this->username) . self::PROJECT_DIR;
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
     * The recipes that could have deployed this project, best first — ids
     * only, because the label and priority are facts about the engine that
     * every install already knows.
     *
     * The counterpart to the recipe the deploy actually used. On its own,
     * "laravel failed" says nothing about whether a better recipe existed;
     * next to `["dockerfile", "laravel", "railpack"]` it says the project
     * shipped a Dockerfile the engine outranked, which is the same question
     * `detection-railpack` answers one rung lower and the reason that signal
     * exists.
     *
     * Read off the tree at the moment of the event, like everything else here:
     * a rolled-back deploy takes the files that decided the answer with it.
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
     * What the package files say this application is, primary ecosystem first.
     *
     * The same readers detection itself used, so a report describes the
     * project the deploy actually saw. {@see AppFacts} decides which parts of
     * that may be transmitted; this only gathers.
     *
     * @param ?string $runtime the ecosystem the deploy chose to build, when known
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
     * The hostnames this project answers on, primary first.
     *
     * Requested by the operator: an event names the application, and until now
     * it never named the address that application is served at. Support work
     * starts by opening the site, and a report that cannot say which site it
     * is about sends somebody back to the panel to look it up.
     *
     * Three sources, because the same project is reachable under more than
     * one name and each answers a different question: the account's own
     * `domain`, the `domains` rows (addon and subdomain names, each carrying
     * its `aliases`), and the tunnel hostnames attached to those domains --
     * the public name the WithoutDNS proxy registered, which is the one a
     * visitor actually types.
     *
     * These are the *real* hostnames, deliberately not redacted. The account
     * name travels as a hash everywhere else in a report and still does; a
     * hostname is the application's public address and the whole point of
     * sending it. Only the main domain is marked, so a reader can tell the
     * site from the `www.` alias beside it.
     *
     * @return list<array{domain: string, primary?: true, type?: string, alias?: true, tunnel?: string}>
     */
    public function domains(): array
    {
        if ($this->user === null) {
            return [];
        }

        $found = [];
        $add = function (string $name, array $facts) use (&$found): void {
            $name = self::normalizeHostname($name);
            if ($name === '') {
                return;
            }

            // First writer wins, and the main domain is written first: a name
            // that is both the account's domain and a `domains` row keeps the
            // primary marking rather than being demoted by the second read.
            if (!isset($found[$name])) {
                $found[$name] = ['domain' => $name] + $facts;
            }
        };

        $add((string) self::safely(fn (): string => (string) $this->user->domain, ''), [
            'primary' => true,
            // The type is stated rather than left to be inferred from
            // `primary`: a reader that wants "the main names" should not have
            // to know that the account column is the main one.
            'type' => 'main',
        ]);

        foreach (self::safely(fn (): array => $this->user->domains()->get()->all(), []) as $domain) {
            if (!$domain instanceof Domain) {
                continue;
            }

            $facts = [];
            $type = self::safely(fn (): string => (string) $domain->type, '');
            if ($type !== '') {
                $facts['type'] = $type;
            }

            $add((string) $domain->domain, $facts);

            foreach (self::tunnelHostnames($domain) as $hostname => $provider) {
                $add($hostname, $provider === '' ? [] : ['tunnel' => $provider]);
            }

            foreach (self::safely(fn (): array => $domain->getAliases(), []) as $alias) {
                if (is_string($alias)) {
                    $add($alias, ['alias' => true]);
                }
            }
        }

        // The cap is applied last, after the primary has had its chance to be
        // in the list at all -- it is written first, so it is never the entry
        // a long alias list pushes out.
        return array_slice(array_values($found), 0, DeployReport::MAX_NAMES);
    }

    /**
     * The public hostnames a domain's tunnels registered.
     *
     * Read through the relation rather than the `tunnels` table so a domain
     * whose rows are missing costs nothing: this runs while a deploy is
     * finishing and cannot fail it.
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
     * A hostname as it is compared: lowercase, no trailing dot, no scheme and
     * no path, and refused outright when it is not a name at all.
     *
     * Not a validator. This only has to stop the report carrying a blank
     * entry or a credential somebody pasted into a domain row — a domain named
     * `https://acme:ghp_token@shop.acme.com/` is a `domains` row somebody
     * typed into, and the report must not relay the credential in it.
     *
     * Public so the rule can be read and tested without an account, the same
     * reason `isPrivateRepo()` is.
     */
    public static function normalizeHostname(string $name): string
    {
        $name = strtolower(trim($name));
        $name = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $name);
        $name = explode('/', $name)[0];
        $name = rtrim($name, '.');

        if ($name === '' || str_contains($name, '@') || !str_contains($name, '.') || str_contains($name, ' ')) {
            return '';
        }

        // Anything outside a hostname's alphabet is not one. Over-strict on
        // purpose, because the alternative is relaying whatever somebody
        // pasted into a domain row: an underscore is legal in some zones and a
        // colon never is, and a name this refuses is a name to look at rather
        // than a name to send.
        return preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $name) === 1 ? $name : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function latestDeploy(DeployLogger $logger): array
    {
        return self::safely(fn (): array => $logger->readLatest() ?? [], []);
    }

    /**
     * @return list<string>
     */
    public function logTail(DeployLogger $logger, int $lines): array
    {
        return self::safely(fn (): array => array_column($logger->tail($lines), 'msg'), []);
    }
}
