<?php

namespace App\Lib\Project;

use App\Auth\TokenAbilities;
use App\Exceptions\ProblemException;
use App\Jobs\ApplyEnvVars;
use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\Models\Admin;
use App\Models\PersonalAccessToken;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\System;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Which projects may use the Engine API, and what the engine hands them.
 *
 * One setting, `api_access_projects`, is the whole record: an entry per allowed
 * project carrying the scope its token was minted with, the token's id and an
 * encrypted copy of its plain value. Nothing outside this class reads it, and
 * the entry it reports never carries the token.
 *
 * Allowing does three things: tells the host firewall to let the project's
 * containers reach :2011, mints a token with the abilities the scope implies,
 * and delivers the URL, the token and, where a client would need it, the CA to
 * the application as environment variables.
 */
class ApiAccess
{
    public const SETTING = 'api_access_projects';

    /** Only this project's own token; it may reach no other. */
    public const SCOPE_SELF = 'self';

    /** Every project on this host. */
    public const SCOPE_ALL = 'all';

    public const ENV_URL = 'PANELALPHA_ENGINE_URL';
    public const ENV_TOKEN = 'PANELALPHA_ENGINE_TOKEN';
    public const ENV_CA = 'PANELALPHA_ENGINE_CA';

    /** @var list<string> */
    public const ENV_KEYS = [self::ENV_URL, self::ENV_TOKEN, self::ENV_CA];

    public const SCOPE_INVALID = 'api_access_scope_invalid';
    public const PROJECT_UNKNOWN = 'api_access_project_unknown';
    public const NOT_ALLOWED = 'api_access_not_allowed';
    public const CONTAINER_ONLY = 'api_access_container_only';
    public const TASK_ACTION = 'api_access_apply';

    /** The one lock every read-modify-write of the setting takes. */
    public const LOCK = 'api-access-projects';

    private const LOCK_SECONDS = 5;

    private const TOKEN_NAME = 'api-access:';

    /** The token the application may still hold from a replacement whose apply failed. */
    private const PENDING = 'pending_revoke_token_id';

    /** Whether that replacement's apply failed, as opposed to not having run yet. */
    private const PENDING_FAILED = 'pending_revoke_failed';

    private ?System $system;

    private ?ApiAccessFirewall $firewall;

    public function __construct(?System $system = null, ?ApiAccessFirewall $firewall = null)
    {
        $this->system = $system;
        $this->firewall = $firewall;
    }

    /**
     * The entry for a project, without the token, or null when it is not allowed.
     *
     * @return null|array<string, mixed>
     */
    public function entryFor(User $user): ?array
    {
        $entry = $this->entryOf($user->username);

        return $entry === null ? null : self::reported($entry);
    }

    /**
     * Every entry, without the tokens.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return array_values(array_map(self::reported(...), $this->entries()));
    }

    /**
     * Allows a project, or changes the scope of one already allowed. Allowing a
     * project that already carries this scope and this MCP flag mints nothing
     * and queues nothing: it only says so. A changed scope changes the token,
     * which is the only way the new abilities can reach the application, and the
     * old one is revoked once the apply that carries the new one has succeeded.
     *
     * @param string|list<string> $scope self, all, or the projects to allow
     * @return array<string, mixed> the entry, without the token
     */
    public function allow(User $user, string|array $scope, bool $mcp): array
    {
        $this->assertContainerProject($user);
        $planned = $this->mutate(fn (): array => $this->recordAllowance($user, $scope, $mcp));
        $this->firewall()->apply(self::allowedProjects($this->entries()), [$user->username]);

        if ($planned['apply']) {
            $this->queueApply($user, $planned['revoke']);
        }

        return self::reported($planned['entry']);
    }

    /**
     * Mints a new token for an allowed project with the scope it already has,
     * and revokes the one it replaces and any older one still tracked once the
     * apply that carries the new one has succeeded.
     *
     * @param ?int $applyTaskId set to the task the new token travels in
     * @return array<string, mixed> the entry, without the token
     */
    public function rotate(User $user, ?int &$applyTaskId = null): array
    {
        $this->assertContainerProject($user);
        $planned = $this->mutate(function () use ($user): array {
            $entry = $this->entryOf($user->username);
            if ($entry === null) {
                throw ProblemException::one(
                    'project',
                    self::NOT_ALLOWED,
                    "Project '{$user->username}' is not allowed to use the Engine API."
                );
            }

            $scope = $entry['scope'] ?? self::SCOPE_SELF;

            return $this->replaceToken(
                $user->username,
                $entry,
                is_array($scope) ? $scope : (string) $scope,
                (bool) ($entry['mcp'] ?? false)
            );
        });
        $applyTaskId = $this->queueApply($user, $planned['revoke']);

        return self::reported($planned['entry']);
    }

    /**
     * Revokes every token this project holds at once -- the current one and one
     * an earlier replacement left behind -- and clears the entry. The environment
     * variables leave the running application on the next apply, which is queued
     * here unless the project is going away with them.
     */
    public function deny(User|string $project, bool $apply = true): bool
    {
        $username = $project instanceof User ? $project->username : $project;
        if (!$this->mutate(fn (): bool => $this->forgetAllowance($username))) {
            return false;
        }

        $this->firewall()->apply(self::allowedProjects($this->entries()), [$username]);

        $user = $project instanceof User ? $project : User::findByUsername($username);
        if ($apply && $user !== null) {
            $this->queueApply($user);
        }

        return true;
    }

    /**
     * The apply that carries a new token finished: the token it replaced, and any
     * older one an earlier failed apply left valid, are revoked now.
     */
    public function confirmRevocation(string $project, ?int $tokenId): void
    {
        $this->mutate(function () use ($project, $tokenId): void {
            self::revokeToken($tokenId);

            $entry = $this->entryOf($project);
            if ($entry === null) {
                return;
            }

            $pending = self::idOf($entry[self::PENDING] ?? null);
            if ($pending !== null && $pending !== $tokenId) {
                self::revokeToken($pending);
            }

            unset($entry[self::PENDING], $entry[self::PENDING_FAILED]);
            $this->store($this->withEntry($entry));
        });
    }

    /**
     * Marks the replacement a project is waiting for as one whose apply failed:
     * the token it would have retired is still valid, and now says so.
     */
    public function markPendingFailure(string $project): void
    {
        $this->mutate(function () use ($project): void {
            $entry = $this->entryOf($project);
            if ($entry === null || self::idOf($entry[self::PENDING] ?? null) === null) {
                return;
            }

            $entry[self::PENDING_FAILED] = true;
            $this->store($this->withEntry($entry));
        });
    }

    /**
     * Whether a project may use the Engine API at all: an entry that was written
     * for it.
     */
    public static function allows(User $user): bool
    {
        return (new self())->entryFor($user) !== null;
    }

    /**
     * A scope as an operator reads it: `self`, `all`, or the projects named.
     *
     * @param string|list<string> $scope
     */
    public static function scopeLabel(string|array $scope): string
    {
        return is_array($scope) ? implode(', ', $scope) : $scope;
    }

    /**
     * Revokes a token by id; one already gone is not an error.
     */
    public static function revokeToken(?int $id): void
    {
        if ($id === null) {
            return;
        }

        PersonalAccessToken::query()->whereKey($id)->update(['revoked_at' => now()]);
    }

    /**
     * The account's environment variables without the engine's own names: one
     * stored before they were refused is neither shown nor stored again.
     *
     * @param array<string, string> $vars
     * @return array<string, string>
     */
    public static function withoutReservedKeys(array $vars): array
    {
        foreach (self::ENV_KEYS as $key) {
            unset($vars[$key]);
        }

        return $vars;
    }

    /**
     * What the allowed project's application is told on every deploy, and
     * nothing at all for a project that is not allowed.
     *
     * @return array<string, string>
     */
    public function envFor(User $user): array
    {
        $entry = $this->entryOf($user->username);
        if ($entry === null) {
            return [];
        }

        $token = self::plainToken($entry);
        if ($token === null) {
            return [];
        }

        $url = $this->apiUrl();
        $env = $url === null ? [] : [self::ENV_URL => $url];
        $env[self::ENV_TOKEN] = $token;

        $ca = self::certificateAuthority($url, $this->servedCertificate());
        if ($ca !== null) {
            $env[self::ENV_CA] = $ca;
        }

        return $env;
    }

    /**
     * Only a container project has somewhere to put the address, the token and the
     * certificate, and an address on the tenant network the firewall can admit. A
     * classic project allowed here would read as allowed and change nothing.
     */
    private function assertContainerProject(User $user): void
    {
        if ($user->project($this->system())->runsInDind()) {
            return;
        }

        throw ProblemException::one(
            'project',
            self::CONTAINER_ONLY,
            "Engine API access is delivered into a container project's application. "
                . "'{$user->username}' is a PHP hosting project: its token would work, but nothing would "
                . 'ever be handed the address, the certificate or the token itself.'
        );
    }

    /**
     * The setting under the one lock every read-modify-write takes, so two
     * allowances granted at once cannot each write the other's entry away. A
     * seam, so a test can shorten the wait or watch the section.
     */
    protected function mutate(callable $work): mixed
    {
        return Cache::lock(self::LOCK, self::LOCK_SECONDS)->block(self::LOCK_SECONDS, $work);
    }

    /**
     * Stores the allowance a call asked for, or finds the one already there.
     *
     * @param string|list<string> $scope
     * @return array{entry: array<string, mixed>, apply: bool, revoke: ?int}
     */
    private function recordAllowance(User $user, string|array $scope, bool $mcp): array
    {
        $previous = $this->entryOf($user->username);
        if ($previous !== null && self::sameAllowance($previous, $scope, $mcp)) {
            return ['entry' => $previous, 'apply' => false, 'revoke' => null];
        }

        return $this->replaceToken($user->username, $previous, $scope, $mcp);
    }

    /**
     * Mints the token the scope needs, stores it and answers the apply that has to
     * carry it, with the token it replaces.
     *
     * @param null|array<string, mixed> $previous
     * @param string|list<string> $scope
     * @return array{entry: array<string, mixed>, apply: bool, revoke: ?int}
     */
    private function replaceToken(string $project, ?array $previous, string|array $scope, bool $mcp): array
    {
        $fresh = $this->freshEntry($project, $previous, $scope, $mcp);
        $this->store($this->withEntry($fresh));

        return ['entry' => $fresh, 'apply' => true, 'revoke' => self::idOf($previous['token_id'] ?? null)];
    }

    /**
     * Whether the allowance already in force is the one the caller names, token
     * aside: the same scope spelled the same way and the same MCP flag.
     *
     * @param array<string, mixed> $entry
     * @param string|list<string> $scope
     */
    private static function sameAllowance(array $entry, string|array $scope, bool $mcp): bool
    {
        return ($entry['mcp'] ?? false) === $mcp
            && self::scopeOf($entry['scope'] ?? self::SCOPE_SELF) === self::scopeOf($scope);
    }

    /**
     * Takes the entry away, revoking every token it holds.
     */
    private function forgetAllowance(string $project): bool
    {
        $entry = $this->entryOf($project);
        if ($entry === null) {
            return false;
        }

        self::revokeToken(self::idOf($entry['token_id'] ?? null));
        self::revokeToken(self::idOf($entry[self::PENDING] ?? null));
        $this->store($this->withoutEntry($project));

        return true;
    }

    /**
     * The certificate :2011 presents, or null when there is none.
     */
    protected function servedCertificate(): ?string
    {
        return (new EngineCertificate($this->system()))->served();
    }

    /**
     * The host the engine runs on; the container's binding when the caller named
     * none, so a test can replace it the way it replaces the engine's own.
     */
    private function system(): System
    {
        return $this->system ?? app(System::class);
    }

    /**
     * The PEM a client has to be given: what :2011 serves is self-signed, or it
     * is not for the name in the URL. A trusted certificate for that name is
     * already in the client's store.
     */
    private static function certificateAuthority(?string $url, ?string $pem): ?string
    {
        if ($pem === null || $pem === '') {
            return null;
        }

        $facts = CertificateFacts::fromPem($pem);
        if ($facts === null) {
            return $pem;
        }

        $status = CertificateStatus::of($facts, (string) (parse_url((string) $url, PHP_URL_HOST) ?: ''));
        if (($status['self_signed'] ?? true) !== true && ($status['covers_domain'] ?? false) === true) {
            return null;
        }

        return $pem;
    }

    private function apiUrl(): ?string
    {
        $domain = trim((string) Setting::get('cert_domain'));
        if ($domain !== '') {
            return 'https://' . $domain . ':2011/api';
        }

        $base = self::hostOnly(rtrim((string) config('app.url'), '/'));

        return $base === null ? null : $base . ':2011/api';
    }

    /**
     * The scheme and the host of an engine URL, without the port it may already
     * carry: a shipped engine answers on `https://<host>:2011`, and the API port
     * has to be added exactly once.
     */
    private static function hostOnly(string $url): ?string
    {
        if (preg_match('#^(https?://[^/]+?)(:\d+)?$#i', $url, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @param string|list<string> $scope
     * @return array<string, mixed>
     */
    private function freshEntry(string $project, ?array $previous, string|array $scope, bool $mcp): array
    {
        $new = Admin::rootAccount()->createToken(
            self::TOKEN_NAME . $project,
            TokenAbilities::build(api: true, mcp: $mcp, projects: self::projectsFor($project, $scope))
        );

        $pending = self::idOf($previous[self::PENDING] ?? null);

        return [
            'project' => $project,
            'scope' => self::scopeOf($scope),
            'mcp' => $mcp,
            'token_id' => (int) $new->accessToken->getKey(),
            'token' => Crypt::encryptString($new->plainTextToken),
            'allowed_at' => $previous['allowed_at'] ?? now()->toIso8601String(),
            self::PENDING => $pending ?? self::idOf($previous['token_id'] ?? null),
            self::PENDING_FAILED => $pending !== null && (bool) ($previous[self::PENDING_FAILED] ?? false),
        ];
    }

    /**
     * The entry's own spelling of the scope: self, all, or the named projects, in
     * one order, so the same set of projects reads the same however it was named.
     *
     * @param string|list<string> $scope
     * @return string|list<string>
     */
    private static function scopeOf(string|array $scope): string|array
    {
        if (is_string($scope)) {
            return $scope;
        }

        $names = array_values(array_unique(array_map('strval', $scope)));
        sort($names);

        return $names;
    }

    /**
     * @param string|list<string> $scope
     * @return list<string>|null null for every project on this host
     */
    private static function projectsFor(string $project, string|array $scope): ?array
    {
        if ($scope === self::SCOPE_ALL) {
            return null;
        }
        if ($scope === self::SCOPE_SELF) {
            return [$project];
        }
        if (!is_array($scope) || $scope === []) {
            throw self::scopeProblem($scope);
        }

        $names = [];
        foreach ($scope as $name) {
            if (!is_string($name) || !User::existsByUsername($name)) {
                throw ProblemException::one(
                    'scope',
                    self::PROJECT_UNKNOWN,
                    sprintf("'%s' is not a project on this engine.", is_string($name) ? $name : gettype($name))
                );
            }
            $names[] = $name;
        }

        return array_values(array_unique($names));
    }

    /**
     * @param mixed $scope
     */
    private static function scopeProblem(mixed $scope): ProblemException
    {
        return ProblemException::one(
            'scope',
            self::SCOPE_INVALID,
            sprintf(
                'A scope is "%s", "%s", or a non-empty list of project names, not %s.',
                self::SCOPE_SELF,
                self::SCOPE_ALL,
                is_array($scope) ? 'an empty list' : gettype($scope)
            )
        );
    }

    private function queueApply(User $user, ?int $revokeTokenId = null): ?int
    {
        $task = Task::start(
            jobType: ApplyEnvVars::class,
            queue: 'default',
            username: $user->username,
            details: ['action' => self::TASK_ACTION, 'project' => $user->username],
        );
        ApplyEnvVars::dispatch($user->username, [], (int) $user->getKey(), $revokeTokenId)->attachTask($task);

        return (int) $task->getKey();
    }

    /**
     * A token id out of a stored value, whatever a hand-edited setting holds.
     */
    private static function idOf(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function plainToken(array $entry): ?string
    {
        $token = $entry['token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            return Crypt::decryptString($token);
        } catch (Throwable) {
            return null;
        }
    }

    private function firewall(): ApiAccessFirewall
    {
        return $this->firewall ??= new ApiAccessFirewall($this->system());
    }

    /**
     * The stored entries. A setting that cannot be read at all -- outside a
     * booted engine, as a deploy step's unit test is -- allows nobody, like the
     * database hostname the deploy reads beside it.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(): array
    {
        try {
            $raw = Setting::get(self::SETTING);
        } catch (Throwable) {
            return [];
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && is_string($entry['project'] ?? null) && $entry['project'] !== '') {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function store(array $entries): void
    {
        Setting::set(self::SETTING, json_encode(array_values($entries)));
    }

    /**
     * @return null|array<string, mixed>
     */
    private function entryOf(string $project): ?array
    {
        foreach ($this->entries() as $entry) {
            if ($entry['project'] === $project) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    private function withEntry(array $entry): array
    {
        $entries = array_values(array_filter(
            $this->entries(),
            static fn (array $existing): bool => $existing['project'] !== $entry['project']
        ));
        $entries[] = $entry;

        return $entries;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function withoutEntry(string $project): array
    {
        return array_values(array_filter(
            $this->entries(),
            static fn (array $entry): bool => $entry['project'] !== $project
        ));
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return list<string>
     */
    private static function allowedProjects(array $entries): array
    {
        return array_values(array_map(
            static fn (array $entry): string => (string) $entry['project'],
            $entries
        ));
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private static function reported(array $entry): array
    {
        unset($entry['token']);
        $pending = self::idOf($entry[self::PENDING] ?? null);
        $entry[self::PENDING] = $pending;
        $entry['warning'] = $pending === null
            ? null
            : self::pendingWarning((bool) ($entry[self::PENDING_FAILED] ?? false));

        return $entry;
    }

    /**
     * What a token the application may still be holding means: it is retired by
     * the apply that replaces it, which may not have run yet.
     */
    public static function pendingWarning(bool $applyFailed): string
    {
        return $applyFailed
            ? 'previous token still valid: the apply that replaces it failed'
            : 'previous token still valid until the apply that replaces it finishes';
    }
}
