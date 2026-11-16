<?php

namespace App\Lib\Deploy\Telemetry;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\Platform\Metadata\AppPackage;
use App\Lib\Deploy\Platform\PlatformStage;
use App\Lib\Limits\ResourceLimit;
use App\System\Project\Dind\AppHealth;

/**
 * Assembles one deploy telemetry report: no username, repository token, server name or address, absolute path or env
 * var value. Tiers are cumulative: 0 metadata, 1 + repository identity (hashed when private), 2 + a redacted log tail.
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
     * Outcomes worth sending: successes give the denominator the failures need. `cancelled` stays out: the customer
     * stopping their own deploy is not an outcome of one.
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
     * A source bundle is not gated by tier: it is its own opt-in, off by default, and this field only describes it (the zip
     * travels separately).
     *
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
     *   server_names?: list<?string>,
     *   log_tail?: list<string>
     * } $input
     * @return array<string, mixed>
     */
    public static function build(array $input): array
    {
        $tier = self::clampTier((int) $input['tier']);
        $username = (string) $input['username'];
        $installId = (string) $input['install_id'];
        $details = $input['details'];
        $private = (bool) ($input['repo_private'] ?? false);
        $checkout = is_array($input['checkout'] ?? null) ? $input['checkout'] : [];
        $repoMask = PrivateRepoMask::for(
            $private,
            [self::stringOrNull($input['repo_url'] ?? null), self::stringOrNull($checkout['remote'] ?? null)],
            [self::stringOrNull($details['git_branch'] ?? null), self::stringOrNull($checkout['branch'] ?? null)]
        );

        $report = self::metadata($input, $tier, $username, $installId, $repoMask);
        $report = self::withObservations($report, $input, $details, $username, $tier, $private, $installId);
        $report = self::withRepository($report, $input, $details, $tier, $private, $installId, $checkout);

        if ($tier >= self::TIER_LOG) {
            $report['log_tail'] = Redactor::tail($input['log_tail'] ?? [], $username, $repoMask);
        }

        if (isset($input['source_bundle']) && is_array($input['source_bundle'])) {
            $report['source_bundle'] = $input['source_bundle'];
        }

        return self::withoutServerNames($report, $input['server_names'] ?? []);
    }

    /**
     * Last, over every field but the hostnames: the server's own name and address can turn up in any of them, the host
     * of a repository it serves included.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function withoutServerNames(array $report, mixed $serverNames): array
    {
        $mask = is_array($serverNames) ? ServerNames::for($serverNames) : null;

        return $mask === null ? $report : self::withHostnamesOf($report, $mask->scrub($report));
    }

    /**
     * The hostnames are the address a visitor types and what monitoring links the application by, so they stay as sent.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $scrubbed
     * @return array<string, mixed>
     */
    private static function withHostnamesOf(array $original, array $scrubbed): array
    {
        foreach (['primary', 'names'] as $key) {
            if (isset($original['domain'][$key])) {
                $scrubbed['domain'][$key] = $original['domain'][$key];
            }
        }

        return $scrubbed;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function metadata(array $input, int $tier, string $username, string $installId, ?PrivateRepoMask $repoMask): array
    {
        $details = $input['details'];
        $latest = $input['latest'];
        $strategy = self::stringOrNull($details['deploy_strategy'] ?? null);
        $runtime = self::stringOrNull($details['deploy_runtime'] ?? null);
        $error = is_string($input['error'] ?? null) ? $input['error'] : '';
        $failure = self::failure((string) $input['outcome'], self::stringOrNull($input['signal'] ?? null), $error, $latest, $username, $repoMask);

        return [
            'id' => (string) $input['id'],
            'schema' => self::SCHEMA,
            'tier' => $tier,
            'occurred_at' => (int) $input['occurred_at'],
            'outcome' => (string) $input['outcome'],
            'account' => Fingerprint::account($installId, $username),
            'fingerprint' => Fingerprint::of($failure['rule'], $strategy, $runtime, $failure['signature']),
            'deploy' => self::deployFacts($input, $details, $latest, $strategy, $runtime),
            'failure' => $failure,
            'timings' => self::timings($latest),
            'limits' => [
                'cpu' => ResourceLimit::enforced(self::floatOrNull($details['cpu_limit'] ?? null)),
                'memory_mb' => self::intOrNull($details['memory_limit'] ?? null),
            ],
        ];
    }

    /**
     * `platform` is the recipe, not the strategy it shares (`html` and `static` fail for different reasons); `candidates`
     * what it was chosen over, in the engine's own recipe vocabulary, which names nothing about the customer.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $latest
     * @return array<string, mixed>
     */
    private static function deployFacts(array $input, array $details, array $latest, ?string $strategy, ?string $runtime): array
    {
        return [
            'id' => self::stringOrNull($latest['id'] ?? null),
            'source' => self::stringOrNull($details['deploy_source'] ?? null),
            'strategy' => $strategy,
            'platform' => self::stringOrNull($details['deploy_platform'] ?? null),
            'label' => self::stringOrNull($details['deploy_label'] ?? null),
            'runtime' => $runtime,
            'port_hint' => self::intOrNull($details['deploy_port'] ?? null),
            'manifests' => self::manifests($input['manifests'] ?? []),
            'candidates' => self::candidates($input['candidates'] ?? []),
        ];
    }

    /**
     * The domain says whether anyone outside could open this install and under which name: without it, one that lost its
     * `panelalpha.online` name to a licensing failure reads like a success. App facts take the tier: a package name is the customer's.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function withObservations(
        array $report,
        array $input,
        array $details,
        string $username,
        int $tier,
        bool $private,
        string $installId
    ): array {
        $health = self::health($details);
        if ($health !== []) {
            $report['health'] = $health;
        }

        $domain = self::domainFacts($details, $username, $input['domains'] ?? []);
        if ($domain !== []) {
            $report['domain'] = $domain;
        }

        $app = AppFacts::build(self::packages($input['packages'] ?? []), $tier, $private, $installId);
        if ($app !== []) {
            $report['app'] = $app;
        }

        return $report;
    }

    /**
     * `checkout` is what the tree on disk says, as opposed to what the account record remembers.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $checkout
     * @return array<string, mixed>
     */
    private static function withRepository(
        array $report,
        array $input,
        array $details,
        int $tier,
        bool $private,
        string $installId,
        array $checkout
    ): array {
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
     * @param  array<string, mixed>  $latest
     */
    public static function isPreCheckRejection(array $latest): bool
    {
        return ($latest[DeployLogger::PRECHECK_REJECTED] ?? false) === true;
    }

    /**
     * A precheck runs inside `cloning`, before the clone itself. The signature is the failing region the customer's message
     * is built from ({@see FailureOutput}): the head of a compose deploy's output is progress, cut off before the reason.
     *
     * @param  array<string, mixed>  $latest
     * @return array{stage: ?string, rule: ?string, explained: bool, message: ?string, signature: string}
     */
    private static function failure(
        string $outcome,
        ?string $signal,
        string $error,
        array $latest,
        string $username,
        ?PrivateRepoMask $repoMask
    ): array {
        $stage = self::isPreCheckRejection($latest)
            ? PlatformStage::PRECHECK
            : self::stringOrNull($latest['stage'] ?? null);

        if ($outcome === self::OUTCOME_RECOVERED || ($signal !== null && $signal !== '')) {
            return self::signalled($stage, $signal, $error, $username, $repoMask);
        }

        $region = FailureOutput::select($error);
        $focus = $region !== '' ? $region : $error;
        $match = DeployFailureExplainer::match($focus) ?? DeployFailureExplainer::match($error);

        return [
            'stage' => $stage,
            'rule' => $match['rule'] ?? null,
            'explained' => $match !== null,
            'message' => self::redactedMessage($match['message'] ?? null, $username, $repoMask),
            'signature' => self::truncate(Redactor::text(self::signatureText($focus, $error, $match), $username, $repoMask), self::MAX_SIGNATURE_BYTES),
        ];
    }

    /** The explainer quotes what the build and the containers printed, so its sentence is redacted like the signature. */
    private static function redactedMessage(?string $message, string $username, ?PrivateRepoMask $repoMask): ?string
    {
        if ($message === null) {
            return null;
        }

        return self::truncate(Redactor::text($message, $username, $repoMask), self::MAX_SIGNATURE_BYTES) ?: null;
    }

    /**
     * A named signal explains itself: the engine chose the name, so there is no build output for the explainer to read,
     * and without it the fingerprint grouping a failure across installs keeps only the strategy and runtime.
     *
     * @return array{stage: ?string, rule: ?string, explained: bool, message: ?string, signature: string}
     */
    private static function signalled(?string $stage, ?string $signal, string $error, string $username, ?PrivateRepoMask $repoMask): array
    {
        return [
            'stage' => $stage,
            'rule' => $signal,
            'explained' => true,
            'message' => self::truncate(Redactor::text($error, $username, $repoMask), self::MAX_SIGNATURE_BYTES) ?: null,
            'signature' => $signal ?? 'recovered',
        ];
    }

    /**
     * A build step the explainer can only say failed is told apart by what it
     * printed last; its exit code alone would group every such failure as one.
     *
     * @param  array{rule: string, message: string}|null  $match
     */
    private static function signatureText(string $focus, string $error, ?array $match): string
    {
        $tail = ($match['rule'] ?? null) === DeployFailureExplainer::BUILD_STEP_FAILED
            ? FailureOutput::failedStepTail($error)
            : '';

        return $tail === '' ? $focus : $tail."\n".$focus;
    }

    /**
     * Wall-clock seconds per stage, from the stage table the logger keeps.
     *
     * @param  array<string, mixed>  $latest
     * @return array<string, int>
     */
    private static function timings(array $latest): array
    {
        $stages = $latest['stages'] ?? [];
        if (! is_array($stages)) {
            return [];
        }

        $timings = [];
        $total = 0;
        foreach ($stages as $stage) {
            if (! is_array($stage) || ! is_string($stage['name'] ?? null)) {
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
     * Repository identity and the checkout it produced: a public repo in the clear, a private one as a salted hash. A tree
     * with no remote can still carry the submodules and LFS pointers a build trips over, so `parsed: false` means "no remote".
     *
     * @param  array<string, mixed>  $checkout  as CheckoutFacts::read() returns it
     * @param  ?string  $source  where the URL came from: `account` or `checkout`
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

        return self::namedRepo($parts, $private, $installId, $source) + $facts;
    }

    /**
     * @param  array{host: string, path: string}  $parts
     * @return array<string, mixed>
     */
    private static function namedRepo(array $parts, bool $private, string $installId, ?string $source): array
    {
        $repo = [
            'present' => true,
            'parsed' => true,
            'private' => $private,
            'host' => $parts['host'],
        ];

        if ($private) {
            $repo['path_hash'] = substr(
                hash('sha256', $installId.'|'.$parts['host'].'/'.$parts['path']),
                0,
                16
            );
        } else {
            $repo['path'] = $parts['path'];
        }

        if ($source !== null) {
            $repo['source'] = $source;
        }

        return $repo;
    }

    /**
     * The half of the repository answer checked on disk (not merely named by the account record). The branch, the customer's
     * own string, travels only for a public repo; the clone's shape either way; the commit date as an age in whole days.
     *
     * @param  array<string, mixed>  $checkout
     * @return array<string, mixed> empty when no tree was there to read
     */
    private static function checkout(array $checkout, bool $private, int $occurredAt): array
    {
        if (($checkout['present'] ?? false) !== true) {
            return [];
        }

        $facts = [
            'checked_out' => true,
            'shallow' => (bool) ($checkout['shallow'] ?? false),
            'submodules' => (bool) ($checkout['submodules'] ?? false),
            'lfs' => (bool) ($checkout['lfs'] ?? false),
        ];

        if (($checkout['detached'] ?? false) === true) {
            $facts['detached'] = true;
        }

        $branch = self::stringOrNull($checkout['branch'] ?? null);
        if ($branch !== null && ! $private) {
            $facts['branch'] = $branch;
        }

        $committedAt = self::intOrNull($checkout['committed_at'] ?? null);
        if ($committedAt !== null && $committedAt > 0 && $occurredAt >= $committedAt) {
            $facts['age_days'] = intdiv($occurredAt - $committedAt, 86400);
        }

        return $facts;
    }

    /**
     * Host and owner/name from an https, ssh or scp-style (`git@github.com:owner/repo.git`) git remote.
     *
     * @return ?array{host: string, path: string}
     */
    public static function parseRepoUrl(string $url): ?array
    {
        $url = trim($url);

        if (preg_match('#^(?:[^@/\s]+@)?([A-Za-z0-9.\-]+):(?!//)([^\s]+)$#', $url, $m) === 1) {
            return ['host' => strtolower($m[1]), 'path' => self::normalizeRepoPath($m[2])];
        }

        $parsed = parse_url($url);
        if (! is_array($parsed) || empty($parsed['host'])) {
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
     * The commit travels for public repositories only: on a private one it fingerprints the customer's history. The deploy
     * snapshot, what was built, is preferred; the checkout answers where the snapshot has no entry.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $checkout
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
     * @return list<string>
     */
    private static function candidates(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $found = [];
        foreach ($ids as $id) {
            if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) !== 1) {
                continue;
            }
            if (! in_array($id, $found, true)) {
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
     * @param  list<string>  $files
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
            if ($lower !== '' && in_array($lower, $known, true) && ! in_array($lower, $found, true)) {
                $found[] = $lower;
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The name this project got, from the deploy's `details.domain` and `details.ssl`, and the hostnames it answers on,
     * since an event about an application nobody can locate is a support thread.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    public static function domainFacts(array $details, string $username, mixed $domains = []): array
    {
        return self::domain($details, $username) + self::names($domains);
    }

    /**
     * `publicly_resolvable` is three-valued: null is "this engine does not control the DNS", not "no". A self-signed
     * certificate's issuer is the name it was made for, so it is redacted like one.
     */
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
            'ssl_issuer' => is_string($ssl['issuer'] ?? null) ? Redactor::line($ssl['issuer'], $username) : null,
        ] as $key => $value) {
            $value = self::stringOrNull($value);
            if ($value !== null) {
                $block[$key] = $value;
            }
        }

        if (is_bool($allocation['publicly_resolvable'] ?? null)) {
            $block['publicly_resolvable'] = $allocation['publicly_resolvable'];
        }

        return self::withFallbackReason($block, $allocation, $username);
    }

    /**
     * `fallback_reason` is the one free-text field, so it goes through the redactor.
     *
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $allocation
     * @return array<string, mixed>
     */
    private static function withFallbackReason(array $block, array $allocation, string $username): array
    {
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
     * The real hostnames the project answers on, primary first, in the clear, so an event locates its application.
     * Bounded and shape-checked, since a wildcard alias can carry hundreds.
     *
     * @return array<string, mixed>
     */
    private static function names(mixed $domains): array
    {
        if (! is_array($domains)) {
            return [];
        }

        [$names, $primary] = self::hostnames($domains);
        if ($names === []) {
            return [];
        }

        return $primary === null ? ['names' => $names] : ['primary' => $primary, 'names' => $names];
    }

    /**
     * @param  array<mixed>  $domains
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private static function hostnames(array $domains): array
    {
        $names = [];
        $primary = null;
        foreach ($domains as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = self::stringOrNull($entry['domain'] ?? null);
            if ($name === null || strlen($name) > 253) {
                continue;
            }

            $facts = self::nameFacts($entry, $name);
            if (($entry['primary'] ?? false) === true) {
                $primary ??= $name;
            }

            $names[] = $facts;
            if (count($names) >= self::MAX_NAMES) {
                break;
            }
        }

        return [$names, $primary];
    }

    /**
     * @param  array<mixed>  $entry
     * @return array<string, mixed>
     */
    private static function nameFacts(array $entry, string $name): array
    {
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

        return $facts;
    }

    /**
     * `reachable` is a different question from the port probe: every port can answer inside the container while the name
     * a visitor types serves another site.
     */
    private static function health(array $details): array
    {
        if (! array_key_exists('health_checked', $details)) {
            return [];
        }

        $health = [
            'checked' => (bool) $details['health_checked'],
            'healthy' => is_bool($details['health_healthy'] ?? null) ? $details['health_healthy'] : null,
        ];

        $reachable = self::stringOrNull($details[AppHealth::DETAIL_REACHABLE] ?? null);
        if ($reachable !== null) {
            $health['reachable'] = $reachable;
        }

        $ports = self::healthPorts($details);
        if ($ports !== []) {
            $health['ports'] = $ports;
        }

        return self::withContentChecks($health, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<array{port: ?int, status: string, http_code: ?int}>
     */
    private static function healthPorts(array $details): array
    {
        $ports = [];
        foreach (is_array($details['health_ports'] ?? null) ? $details['health_ports'] : [] as $port) {
            if (! is_array($port) || ! is_string($port['status'] ?? null)) {
                continue;
            }
            $ports[] = [
                'port' => self::intOrNull($port['port'] ?? null),
                'status' => $port['status'],
                'http_code' => self::intOrNull($port['http_code'] ?? null),
            ];
        }

        return $ports;
    }

    /**
     * `serving` is what answered, which a port probe cannot tell from the engine's own placeholder. A failed check keeps
     * its id and severity only: the message names the customer's own files, and the id makes it countable across installs.
     *
     * @param  array<string, mixed>  $health
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function withContentChecks(array $health, array $details): array
    {
        $serving = $details['health_serving'] ?? null;
        if (is_string($serving) && $serving !== '') {
            $health['serving'] = $serving;
        }

        $failed = [];
        foreach (is_array($details['health_failed_checks'] ?? null) ? $details['health_failed_checks'] : [] as $check) {
            if (! is_array($check) || ! is_string($check['id'] ?? null)) {
                continue;
            }
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
     * @return list<AppPackage>
     */
    private static function packages(mixed $packages): array
    {
        if (! is_array($packages)) {
            return [];
        }

        return array_values(array_filter(
            $packages,
            static fn (mixed $package): bool => $package instanceof AppPackage
        ));
    }

    private static function truncate(string $text, int $bytes): string
    {
        return strlen($text) > $bytes ? substr($text, 0, $bytes).'…' : $text;
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
