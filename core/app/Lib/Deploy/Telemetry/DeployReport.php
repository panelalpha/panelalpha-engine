<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\PlatformStage;
use App\System\Project\Dind\AppHealth;

/**
 * Assembles one deploy telemetry report: no username, repository token, host
 * address, absolute path or env var value. Tiers are cumulative — 0 metadata, 1
 * + repository identity (hashed when private), 2 + a redacted log tail.
 */
class DeployReport
{
    public const SCHEMA = 1;

    public const TIER_METADATA = 0;
    public const TIER_REPO = 1;
    public const TIER_LOG = 2;

    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PARTIAL = 'partial';
    public const OUTCOME_CANCELLED = 'cancelled';
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_RECOVERED = 'recovered';

    /**
     * Outcomes worth sending: successes give the denominator the failures need.
     * `cancelled` stays out — the customer stopping their own deploy is not an
     * outcome of one.
     */
    public const REPORTABLE_OUTCOMES = [
        self::OUTCOME_FAILED,
        self::OUTCOME_PARTIAL,
        self::OUTCOME_RECOVERED,
        self::OUTCOME_SUCCESS,
    ];

    /** Longest failure signature kept, in bytes. */
    private const MAX_SIGNATURE_BYTES = 600;

    /**
     * Recipe ids kept per report: the bound that stops a bad `detect` block from
     * dumping the manifest directory into every report.
     */
    private const MAX_CANDIDATES = 12;

    /**
     * Hostnames kept per report. The gatherer applies the same number, so it does
     * not pay to read what would be thrown away.
     */
    public const MAX_NAMES = 50;

    /**
     * @param array{
     *   id: string,
     *   occurred_at: int,
     *   outcome: string,
     *   tier: int,
     *   install_id: string,
     *   username: string,
     *   latest: array<string, mixed>,
     *   details: array<string, mixed>,
     *   error: ?string,
     *   signal?: ?string,
     *   repo_url?: ?string,
     *   repo_private?: bool,
     *   repo_source?: ?string,
     *   checkout?: array<string, mixed>,
     *   manifests?: list<string>,
     *   candidates?: list<string>,
     *   packages?: list<AppPackage>,
     *   domains?: list<array<string, mixed>>,
     *   log_tail?: list<string>
     * } $input
     *
     * @return array<string, mixed>
     */
    public static function build(array $input): array
    {
        $tier = self::clampTier((int) $input['tier']);
        $username = (string) $input['username'];
        $installId = (string) $input['install_id'];
        $details = $input['details'];
        $latest = $input['latest'];

        $strategy = self::stringOrNull($details['deploy_strategy'] ?? null);
        $runtime = self::stringOrNull($details['deploy_runtime'] ?? null);
        $error = is_string($input['error'] ?? null) ? $input['error'] : '';
        $private = (bool) ($input['repo_private'] ?? false);

        $failure = self::failure(
            (string) $input['outcome'],
            self::stringOrNull($input['signal'] ?? null),
            $error,
            $latest,
            $username
        );

        $report = [
            'id' => (string) $input['id'],
            'schema' => self::SCHEMA,
            'tier' => $tier,
            'occurred_at' => (int) $input['occurred_at'],
            'outcome' => (string) $input['outcome'],
            'account' => Fingerprint::account($installId, $username),
            'fingerprint' => Fingerprint::of($failure['rule'], $strategy, $runtime, $failure['signature']),
            'deploy' => [
                'id' => self::stringOrNull($latest['id'] ?? null),
                'source' => self::stringOrNull($details['deploy_source'] ?? null),
                'strategy' => $strategy,
                // The recipe, not the strategy it shares: `html` and `static` are
                // both the static strategy and fail for different reasons.
                'platform' => self::stringOrNull($details['deploy_platform'] ?? null),
                'label' => self::stringOrNull($details['deploy_label'] ?? null),
                'runtime' => $runtime,
                'port_hint' => self::intOrNull($details['deploy_port'] ?? null),
                'manifests' => self::manifests($input['manifests'] ?? []),
                // What `platform` was chosen over. Recipe ids are the engine's own
                // vocabulary, so this names nothing about the customer.
                'candidates' => self::candidates($input['candidates'] ?? []),
            ],
            'failure' => $failure,
            'timings' => self::timings($latest),
            'limits' => [
                'cpu' => self::floatOrNull($details['cpu_limit'] ?? null),
                'memory_mb' => self::intOrNull($details['memory_limit'] ?? null),
            ],
        ];

        // The tier is passed in: a framework version is a fact about somebody
        // else's package, the customer's own package name is not.
        $health = self::health($details);
        if ($health !== []) {
            $report['health'] = $health;
        }

        // Whether anyone outside could open this install, and under which name:
        // without the first, one that lost its `panelalpha.online` name to a
        // licensing failure reads like a success. Hostnames off the account rows.
        $domain = self::domainFacts($details, $username, $input['domains'] ?? []);
        if ($domain !== []) {
            $report['domain'] = $domain;
        }

        $app = AppFacts::build(
            self::packages($input['packages'] ?? []),
            $tier,
            $private,
            $installId
        );
        if ($app !== []) {
            $report['app'] = $app;
        }

        // What the tree on disk says, as opposed to what the account record
        // remembers.
        $checkout = is_array($input['checkout'] ?? null) ? $input['checkout'] : [];

        if ($tier >= self::TIER_REPO) {
            $report['repo'] = self::repo(
                self::stringOrNull($input['repo_url'] ?? null),
                $private,
                $installId,
                $checkout,
                self::stringOrNull($input['repo_source'] ?? null),
                (int) $input['occurred_at']
            );
            $report['deploy']['git_commit'] = self::commit($details, $checkout, $private);
        }

        if ($tier >= self::TIER_LOG) {
            $report['log_tail'] = Redactor::tail($input['log_tail'] ?? [], $username);
        }

        // Not gated by tier: a source bundle is its own opt-in, off by default, and
        // this field only ever describes the bundle — the zip travels separately.
        if (isset($input['source_bundle']) && is_array($input['source_bundle'])) {
            $report['source_bundle'] = $input['source_bundle'];
        }

        return $report;
    }

    public static function clampTier(int $tier): int
    {
        return max(self::TIER_METADATA, min(self::TIER_LOG, $tier));
    }

    public static function isReportable(string $outcome): bool
    {
        return in_array($outcome, self::REPORTABLE_OUTCOMES, true);
    }

    /**
     * @param array<string, mixed> $latest
     */
    public static function isPreCheckRejection(array $latest): bool
    {
        return ($latest[DeployLogger::PRECHECK_REJECTED] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $latest
     * @return array{stage: ?string, rule: ?string, explained: bool, message: ?string, signature: string}
     */
    private static function failure(
        string $outcome,
        ?string $signal,
        string $error,
        array $latest,
        string $username
    ): array {
        // A precheck runs inside `cloning`, before the clone itself.
        $stage = self::isPreCheckRejection($latest)
            ? PlatformStage::PRECHECK
            : self::stringOrNull($latest['stage'] ?? null);

        // A named signal explains itself: the engine chose the name, so there is no
        // build output for the explainer to read. Without it the fingerprint that
        // groups a failure across installs keeps only the strategy and runtime.
        if ($outcome === self::OUTCOME_RECOVERED || ($signal !== null && $signal !== '')) {
            return [
                'stage' => $stage,
                'rule' => $signal,
                'explained' => true,
                'message' => self::truncate(Redactor::text($error, $username), self::MAX_SIGNATURE_BYTES) ?: null,
                'signature' => $signal ?? 'recovered',
            ];
        }

        $match = DeployFailureExplainer::match($error);

        return [
            'stage' => $stage,
            'rule' => $match['rule'] ?? null,
            'explained' => $match !== null,
            'message' => $match['message'] ?? null,
            'signature' => self::truncate(Redactor::text($error, $username), self::MAX_SIGNATURE_BYTES),
        ];
    }

    /**
     * Wall-clock seconds per stage, from the stage table the logger keeps.
     *
     * @param array<string, mixed> $latest
     * @return array<string, int>
     */
    private static function timings(array $latest): array
    {
        $stages = $latest['stages'] ?? [];
        if (!is_array($stages)) {
            return [];
        }

        $timings = [];
        $total = 0;
        foreach ($stages as $stage) {
            if (!is_array($stage) || !is_string($stage['name'] ?? null)) {
                continue;
            }
            $started = self::intOrNull($stage['started_at'] ?? null);
            $finished = self::intOrNull($stage['finished_at'] ?? null);
            if ($started === null || $finished === null || $finished < $started) {
                continue;
            }
            $elapsed = $finished - $started;
            $timings[$stage['name']] = $elapsed;
            $total += $elapsed;
        }

        if ($timings !== []) {
            $timings['total'] = $total;
        }

        return $timings;
    }

    /**
     * Repository identity and the checkout it produced: a public repo is named in
     * the clear, a private one reduced to a salted hash. A tree with no remote is
     * still a repository — it is cloned, checked out, and can carry the submodules
     * and LFS pointers a build trips over — so `parsed: false` stands for "no remote".
     *
     * @param array<string, mixed> $checkout as CheckoutFacts::read() returns it
     * @param ?string $source where the URL came from: `account` or `checkout`
     * @return array<string, mixed>
     */
    private static function repo(
        ?string $url,
        bool $private,
        string $installId,
        array $checkout,
        ?string $source,
        int $occurredAt
    ): array {
        $facts = self::checkout($checkout, $private, $occurredAt);

        if ($url === null || trim($url) === '') {
            return $facts === []
                ? ['present' => false]
                : ['present' => true, 'parsed' => false, 'private' => $private] + $facts;
        }

        $parts = self::parseRepoUrl($url);
        if ($parts === null) {
            return ['present' => true, 'parsed' => false, 'private' => $private] + $facts;
        }

        $repo = [
            'present' => true,
            'parsed' => true,
            'private' => $private,
            'host' => $parts['host'],
        ];

        if ($private) {
            $repo['path_hash'] = substr(
                hash('sha256', $installId . '|' . $parts['host'] . '/' . $parts['path']),
                0,
                16
            );
        } else {
            $repo['path'] = $parts['path'];
        }

        if ($source !== null) {
            $repo['source'] = $source;
        }

        return $repo + $facts;
    }

    /**
     * The half of the repository answer that came off the disk. The branch is the
     * customer's own string, so it travels only for a public repo; shallow,
     * submodules and lfs describe the shape of the clone and travel either way.
     * The commit date travels as an age in whole days, not as its timestamp.
     *
     * @param array<string, mixed> $checkout
     * @return array<string, mixed> empty when no tree was there to read
     */
    private static function checkout(array $checkout, bool $private, int $occurredAt): array
    {
        if (($checkout['present'] ?? false) !== true) {
            return [];
        }

        $facts = [
            // "the account record named a repo" and "a checkout was there when we
            // looked" are different claims, and only the second is verified.
            'checked_out' => true,
            'shallow' => (bool) ($checkout['shallow'] ?? false),
            'submodules' => (bool) ($checkout['submodules'] ?? false),
            'lfs' => (bool) ($checkout['lfs'] ?? false),
        ];

        if (($checkout['detached'] ?? false) === true) {
            $facts['detached'] = true;
        }

        $branch = self::stringOrNull($checkout['branch'] ?? null);
        if ($branch !== null && !$private) {
            $facts['branch'] = $branch;
        }

        $committedAt = self::intOrNull($checkout['committed_at'] ?? null);
        if ($committedAt !== null && $committedAt > 0 && $occurredAt >= $committedAt) {
            $facts['age_days'] = intdiv($occurredAt - $committedAt, 86400);
        }

        return $facts;
    }

    /**
     * Host and owner/name from an https, ssh or scp-style git remote.
     *
     * @return ?array{host: string, path: string}
     */
    public static function parseRepoUrl(string $url): ?array
    {
        $url = trim($url);

        // scp-style: git@github.com:owner/repo.git
        if (preg_match('#^(?:[^@/\s]+@)?([A-Za-z0-9.\-]+):(?!//)([^\s]+)$#', $url, $m) === 1) {
            return ['host' => strtolower($m[1]), 'path' => self::normalizeRepoPath($m[2])];
        }

        $parsed = parse_url($url);
        if (!is_array($parsed) || empty($parsed['host'])) {
            return null;
        }

        return [
            'host' => strtolower((string) $parsed['host']),
            'path' => self::normalizeRepoPath((string) ($parsed['path'] ?? '')),
        ];
    }

    private static function normalizeRepoPath(string $path): string
    {
        $path = trim($path, '/');
        if (str_ends_with(strtolower($path), '.git')) {
            $path = substr($path, 0, -4);
        }

        return $path;
    }

    /**
     * The commit only travels for public repositories: on a private one it is a
     * fingerprint of the customer's own history. The deploy snapshot is preferred
     * because it is what the deploy built; the checkout answers for every source
     * the snapshot has no entry for.
     *
     * @param array<string, mixed> $details
     * @param array<string, mixed> $checkout
     */
    private static function commit(array $details, array $checkout, bool $private): ?string
    {
        if ($private) {
            return null;
        }
        $commit = self::stringOrNull($details['git_commit'] ?? null)
            ?? self::stringOrNull($checkout['commit'] ?? null);

        return $commit === null ? null : substr($commit, 0, 12);
    }

    /**
     * Recipe ids, in the order PlatformCandidates listed them: the first is the
     * one that ran.
     *
     * @param mixed $ids
     * @return list<string>
     */
    private static function candidates(mixed $ids): array
    {
        if (!is_array($ids)) {
            return [];
        }

        $found = [];
        foreach ($ids as $id) {
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
                continue;
            }
            if (!in_array($id, $found, true)) {
                $found[] = $id;
            }
            if (count($found) >= self::MAX_CANDIDATES) {
                break;
            }
        }

        return $found;
    }

    /**
     * Root manifest filenames only — never the customer's own file names.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private static function manifests(array $files): array
    {
        $known = [
            'package.json', 'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb',
            'composer.json', 'composer.lock', 'artisan',
            'gemfile', 'gemfile.lock', 'go.mod', 'go.sum', 'cargo.toml',
            'requirements.txt', 'pyproject.toml', 'poetry.lock', 'pipfile',
            'pom.xml', 'build.gradle', 'build.gradle.kts',
            'dockerfile', 'docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml',
            'procfile', 'panelalpha.md', 'railpack.json', 'nixpacks.toml',
        ];

        $found = [];
        foreach ($files as $file) {
            $lower = strtolower(trim((string) $file));
            if ($lower !== '' && in_array($lower, $known, true) && !in_array($lower, $found, true)) {
                $found[] = $lower;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The name this project got, what it is worth, and what it actually is. The
     * categories come from the deploy's own `details.domain` and `details.ssl`
     * snapshots; the names are the real hostnames, in the clear, since an event
     * about an application nobody can locate is a support thread. `fallback_reason`
     * is the one free-text field and goes through the redactor.
     *
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    public static function domainFacts(array $details, string $username, mixed $domains = []): array
    {
        return self::domain($details, $username) + self::names($domains);
    }

    private static function domain(array $details, string $username): array
    {
        $allocation = is_array($details['domain'] ?? null) ? $details['domain'] : [];
        $ssl = is_array($details['ssl'] ?? null) ? $details['ssl'] : [];

        $block = [];
        foreach ([
            'source' => $allocation['source'] ?? null,
            'tunnel' => $allocation['tunnel'] ?? null,
            'tls_terminated_at' => $allocation['tls_terminated_at'] ?? null,
            'ssl_status' => $ssl['status'] ?? null,
            'ssl_issuer' => $ssl['issuer'] ?? null,
        ] as $key => $value) {
            $value = self::stringOrNull($value);
            if ($value !== null) {
                $block[$key] = $value;
            }
        }

        // Three-valued: null is "this engine does not control the DNS", not "no".
        if (is_bool($allocation['publicly_resolvable'] ?? null)) {
            $block['publicly_resolvable'] = $allocation['publicly_resolvable'];
        }

        $reason = self::stringOrNull($allocation['fallback_reason'] ?? null);
        if ($reason !== null) {
            $redacted = Redactor::line($reason, $username, self::MAX_SIGNATURE_BYTES);
            if ($redacted !== '') {
                $block['fallback_reason'] = $redacted;
            }
        }

        return $block;
    }

    /**
     * The real hostnames the project answers on, primary first: the application's
     * public address, not anything private, with the account name still a hash.
     * Bounded and shape-checked, since a wildcard alias can carry hundreds.
     *
     * @param mixed $domains
     * @return array<string, mixed>
     */
    private static function names(mixed $domains): array
    {
        if (!is_array($domains)) {
            return [];
        }

        $names = [];
        $primary = null;
        foreach ($domains as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = self::stringOrNull($entry['domain'] ?? null);
            if ($name === null || strlen($name) > 253) {
                continue;
            }

            $facts = ['domain' => $name];
            foreach (['type' => 'type', 'tunnel' => 'tunnel'] as $from => $to) {
                $value = self::stringOrNull($entry[$from] ?? null);
                if ($value !== null && strlen($value) <= 64) {
                    $facts[$to] = $value;
                }
            }
            if (($entry['alias'] ?? false) === true) {
                $facts['alias'] = true;
            }
            if (($entry['primary'] ?? false) === true) {
                $primary ??= $name;
            }

            $names[] = $facts;
            if (count($names) >= self::MAX_NAMES) {
                break;
            }
        }

        if ($names === []) {
            return [];
        }

        return $primary === null ? ['names' => $names] : ['primary' => $primary, 'names' => $names];
    }

    private static function health(array $details): array
    {
        if (!array_key_exists('health_checked', $details)) {
            return [];
        }

        $health = [
            'checked' => (bool) $details['health_checked'],
            'healthy' => is_bool($details['health_healthy'] ?? null) ? $details['health_healthy'] : null,
        ];

        // A different question from the port probe: every port can answer inside
        // the container while the name a visitor types serves another site.
        $reachable = self::stringOrNull($details[AppHealth::DETAIL_REACHABLE] ?? null);
        if ($reachable !== null) {
            $health['reachable'] = $reachable;
        }

        $ports = [];
        foreach (is_array($details['health_ports'] ?? null) ? $details['health_ports'] : [] as $port) {
            if (!is_array($port) || !is_string($port['status'] ?? null)) {
                continue;
            }
            $ports[] = [
                'port' => self::intOrNull($port['port'] ?? null),
                'status' => $port['status'],
                'http_code' => self::intOrNull($port['http_code'] ?? null),
            ];
        }
        if ($ports !== []) {
            $health['ports'] = $ports;
        }

        // What the application was serving, as opposed to whether anything answered:
        // a port probe cannot tell the engine's own placeholder from a homepage.
        $serving = $details['health_serving'] ?? null;
        if (is_string($serving) && $serving !== '') {
            $health['serving'] = $serving;
        }

        $failed = [];
        foreach (is_array($details['health_failed_checks'] ?? null) ? $details['health_failed_checks'] : [] as $check) {
            if (!is_array($check) || !is_string($check['id'] ?? null)) {
                continue;
            }
            // The id and its severity only: the message names the customer's own
            // files, and the id is what makes a check countable across installs.
            $failed[] = [
                'id' => $check['id'],
                'severity' => is_string($check['severity'] ?? null) ? $check['severity'] : null,
            ];
        }
        if ($failed !== []) {
            $health['failed'] = $failed;
        }

        return $health;
    }

    /**
     * @param mixed $packages
     * @return list<AppPackage>
     */
    private static function packages(mixed $packages): array
    {
        if (!is_array($packages)) {
            return [];
        }

        return array_values(array_filter(
            $packages,
            static fn (mixed $package): bool => $package instanceof AppPackage
        ));
    }

    private static function truncate(string $text, int $bytes): string
    {
        return strlen($text) > $bytes ? substr($text, 0, $bytes) . '…' : $text;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (int) $value
            : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_float($value) || is_int($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }
}
