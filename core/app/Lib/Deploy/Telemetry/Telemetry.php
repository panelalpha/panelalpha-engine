<?php

namespace App\Lib\Deploy\Telemetry;

use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\DeploymentSnapshot;
use App\Integrations\Monitoring\PanelAlphaMonitoring;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deploy telemetry: what the shape of failures across installs looks like, so
 * `resources/sources/` gains the app config that is needed next. Entry points
 * are `captureDeploy()`, `signal()`, `captureHealth()` and `captureBugReport()`.
 *
 * None may throw — a telemetry bug that fails a deploy costs more than every
 * report it sent, so each is wrapped and a failure is a debug line. A bug
 * report is the exception that answers back: an install that cannot ship it
 * returns an error instead of a quiet local write.
 */
class Telemetry
{
    /**
     * One event type per outcome, under `project.` — the API route, the CLI
     * commands and the docs all call a hosting account that.
     *
     * The leaf is the outcome, so installs of any kind are a prefix match and
     * failures an exact one.
     */
    public const EVENT_SUCCESS = 'project.install.success';
    public const EVENT_FAIL = 'project.install.fail';
    public const EVENT_PARTIAL = 'project.install.partial';
    public const EVENT_RECOVERED = 'project.install.recovered';
    public const EVENT_CANCELLED = 'project.install.cancelled';

    /** An outcome no version of this engine knows how to name. */
    public const EVENT_UNKNOWN = 'project.install';

    /**
     * A bug somebody reported by hand.
     *
     * Its own family, not a leaf under `project.install.`: nothing was
     * deployed, so it would otherwise land in every query that counts installs
     * by prefix match.
     */
    public const EVENT_BUG_REPORT = 'support.bug_report';

    /**
     * An application that is up and not serving itself, found by the periodic
     * sweep, not by a deploy.
     *
     * Its own family for the same reason: nothing was installed, and
     * `recovered` would claim something was put right when it was not.
     */
    public const EVENT_HEALTH = 'project.health.degraded';

    /** The `kind` a health finding carries. A deploy report carries none. */
    public const KIND_HEALTH = 'health';

    /** Deploy-log lines attached at tier 2. Redactor caps the bytes again. */
    private const LOG_TAIL_LINES = 120;

    /** Seconds one port of a bug report's health probe may take. */
    private const HEALTH_TIMEOUT = 5;

    /** A signal names itself with a stable slug, e.g. 'port-realigned'. */
    private const SIGNAL_SLUG = '/^[a-z0-9-]{1,64}$/';

    private static ?string $installId = null;
    private static ?Spool $spool = null;

    public static function enabled(): bool
    {
        return NotificationPreferences::isTelemetryEnabled();
    }

    /**
     * Whether an operator may file a bug report from this install.
     *
     * A second switch under the first: an install happy to send anonymous
     * deploy statistics may still not want a free-text field a person can type
     * anything into leaving the box. Both have to be on.
     */
    public static function bugReportsEnabled(): bool
    {
        return self::enabled() && (bool) config('telemetry.bug_reports.enabled', true);
    }

    /**
     * The route on the monitoring host that accepts a batch of reports.
     *
     * The literal lives here as well as in `config/telemetry.php` because the
     * config file is the override and this is the answer: the fallback only
     * applies when the config key is missing, so two disagreeing copies would
     * be invisible in exactly the situation the fallback exists for. Config
     * carries `Telemetry::EVENTS_PATH` and the env variable overrides that.
     *
     * `/api/v1/events` is the shared event route the ingest serves; an earlier
     * `/v1/reports` default was posted at nothing.
     */
    public const EVENTS_PATH = '/api/v1/events';

    /**
     * The configured route, or this engine's own answer when nothing set one.
     *
     * A blank override means "unset", not "post at the host root": an empty path
     * would send reports to whatever that host serves at `/` — a web page.
     */
    public static function eventsPath(): string
    {
        $configured = trim((string) config('telemetry.reports_path', ''));

        return $configured !== '' ? $configured : self::EVENTS_PATH;
    }

    /**
     * Where a batch of reports is POSTed, or '' when nothing is configured.
     *
     * {@see PanelAlphaMonitoring}, not Connect: Connect is an integration the
     * engine calls to get something done. Addressing telemetry at Connect made
     * it 405.
     */
    public static function endpoint(): string
    {
        return PanelAlphaMonitoring::url(self::eventsPath());
    }

    public static function tier(): int
    {
        return DeployReport::clampTier((int) config('telemetry.tier', DeployReport::TIER_LOG));
    }

    /**
     * Source-bundle mode: `off`, `unexplained` or `failed`.
     *
     * Not a fourth tier. Tiers are increasing detail *about a failure*; a
     * source bundle is the customer's intellectual property. Raising the tier
     * must never turn this on as a side effect.
     */
    public static function sourceBundleMode(): string
    {
        return SourceBundlePolicy::normalizeMode(
            (string) config('telemetry.source_bundle.mode', SourceBundlePolicy::MODE_OFF)
        );
    }

    public static function spool(): Spool
    {
        return self::$spool ??= new Spool(
            (string) config('telemetry.spool_dir', storage_path('app/telemetry/outbox'))
        );
    }

    /**
     * The install id: the pinned value if there is one, otherwise derived from
     * machine facts and pinned for next time.
     *
     * '' when neither is possible — the shipper holds the reports instead of
     * sending them under an id that collides with every unprobeable install.
     */
    public static function installId(): string
    {
        if (self::$installId !== null) {
            return self::$installId;
        }

        $pinPath = (string) config('telemetry.pin_file', InstallFingerprint::PIN_FILE);

        $pinned = InstallFingerprint::readPin($pinPath);
        if ($pinned !== null) {
            return self::$installId = $pinned;
        }

        try {
            $id = InstallFingerprint::derive(HostFacts::forFingerprint(new System()));
        } catch (\Throwable $e) {
            Log::debug('Telemetry could not derive an install id: ' . $e->getMessage());
            $id = '';
        }

        if ($id !== '') {
            InstallFingerprint::writePin($id, $pinPath);
        }

        return self::$installId = $id;
    }

    public static function resetCache(): void
    {
        self::$installId = null;
        self::$spool = null;
        HostFacts::resetProbeCache();
    }

    /**
     * A deploy finished. Called from {@see DeployLogger::finish()}, the single
     * choke point every terminal status passes through.
     */
    public static function captureDeploy(
        DeployLogger $logger,
        string $username,
        string $outcome,
        ?string $error
    ): void {
        try {
            $fields = new TelemetryFields($username);
            $reportId = (string) Str::ulid();
            $input = self::deployInput($fields, $logger, $outcome, $error, $reportId);
            $sendable = self::isSendable($fields, $outcome, $input['latest']);

            if ($sendable) {
                // Cut now, not at ship time: a failed account creation is rolled
                // back the moment this returns, so there will be no source left
                // to zip.
                $bundle = self::captureSourceBundle($logger, $username, $outcome, $error, $reportId);
                if ($bundle !== null) {
                    $input['source_bundle'] = $bundle;
                }
            }

            $report = DeployReport::build($input);

            self::record($report);

            if ($sendable) {
                self::spool()->put($report);
            }
        } catch (\Throwable $e) {
            Log::debug('Telemetry capture failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function deployInput(
        TelemetryFields $fields,
        DeployLogger $logger,
        string $outcome,
        ?string $error,
        string $reportId
    ): array {
        $tier = self::safely(fn (): int => self::tier(), DeployReport::TIER_METADATA);
        $details = $fields->accountDetails();

        return [
            'id' => $reportId,
            'occurred_at' => time(),
            'outcome' => $outcome,
            'tier' => $tier,
            'install_id' => self::safely(fn (): string => self::installId(), ''),
            'username' => $fields->username,
            'latest' => $fields->latestDeploy($logger),
            'details' => $details,
            'error' => $error,
            // A partial deploy that a health check caused names the check
            // instead of being fingerprinted on its own sentence: otherwise
            // the same failing check grouped as two problems depending on the
            // project's warning text.
            'signal' => self::healthSignal($outcome, $details),
            // No log tail on a clean success: successes are most of the traffic
            // and 16 KB of build output ending in "Deploy finished
            // successfully" answers nothing. The local log still records it.
            'log_tail' => $tier >= DeployReport::TIER_LOG && $outcome !== DeployReport::OUTCOME_SUCCESS
                ? $fields->logTail($logger, self::LOG_TAIL_LINES)
                : [],
        ] + self::projectFacts($fields, $details);
    }

    /**
     * Everything that can stop a report being *sent* — none of it stops the
     * report being written down. An install with telemetry off, one that cannot
     * fingerprint its machine, or a template this pipeline does not report on
     * still leaves the operator a record to hand over later. Neither does a
     * deploy a precheck refused: the host failed the app's requirements
     * before anything was deployed, so it is not a deploy failure.
     *
     * @param array<string, mixed> $latest
     */
    private static function isSendable(TelemetryFields $fields, string $outcome, array $latest): bool
    {
        return !DeployReport::isPreCheckRejection($latest)
            && self::enabled()
            && DeployReport::isReportable($outcome)
            && self::safely(fn (): string => self::installId(), '') !== ''
            && $fields->isDindAccount();
    }

    /**
     * The stable name of a deploy that finished partial because the
     * application is not serving itself.
     *
     * The same slug the six-hourly sweep uses: the fact is the same whether it
     * was noticed as the deploy finished or six hours later, and different
     * fingerprints would split every such finding in half.
     *
     * Only errors count, because only errors make a deploy partial. Anything
     * else falls through to {@see DeployFailureExplainer}.
     *
     * @param array<string, mixed> $details
     */
    private static function healthSignal(string $outcome, array $details): ?string
    {
        if ($outcome !== DeployReport::OUTCOME_PARTIAL) {
            return null;
        }

        if (AppHealth::errorCheckMessages($details) === []) {
            return null;
        }

        $serving = $details[AppHealth::DETAIL_SERVING] ?? null;

        return is_string($serving) && $serving !== '' ? 'health-' . $serving : null;
    }

    /**
     * What this account's project is, on the terms every event states it.
     *
     * One block, not three copies: "which application was this?" is the same
     * question for a failed deploy, a recovered signal and a health finding —
     * and the three had already drifted, with the health event arriving with no
     * `app` block and unable to be grouped by framework or manifest layout.
     *
     * Read at the moment the event is made, off the tree the account has.
     * {@see DeployReport} decides what may be transmitted at which tier.
     *
     * @param array<string, mixed> $details the account's details
     * @return array<string, mixed>
     */
    private static function projectFacts(TelemetryFields $fields, array $details): array
    {
        return [
            'repo_url' => $fields->repoUrl(),
            'repo_private' => $fields->repoIsPrivate(),
            'repo_source' => $fields->repoSource(),
            // Read off the tree, not the account record: what was actually
            // checked out is what the build ran on, and it is the only thing
            // naming a repository the record never heard of -- a
            // `POST /git/connect`, or an archive carrying a `.git`.
            'checkout' => $fields->checkout(),
            'manifests' => $fields->manifests(),
            // Which recipes could have run as well as which did: a failure rate
            // per recipe is only actionable against the alternative.
            'candidates' => $fields->candidates(),
            // Read while the account still exists. A failed deploy is rolled
            // back moments after this returns, taking composer.json with it.
            'packages' => $fields->packages(self::runtimeOf($details)),
            // The address the application is served at, which nothing else in a
            // report states -- a degraded site nobody could open was reported
            // without the name anyone would have tried.
            'domains' => $fields->domains(),
        ];
    }

    /**
     * The ecosystem the deploy chose to build, so metadata reports on that half
     * of a polyglot project, not on whichever reader sorts first.
     *
     * @param array<string, mixed> $details
     */
    private static function runtimeOf(array $details): ?string
    {
        $runtime = $details['deploy_runtime'] ?? null;

        return is_string($runtime) && $runtime !== '' ? $runtime : null;
    }

    /**
     * @see TelemetryFields::safely()
     *
     * @template T
     * @param callable(): T $value
     * @param T $default
     * @return T
     */
    private static function safely(callable $value, mixed $default): mixed
    {
        return TelemetryFields::safely($value, $default);
    }

    /**
     * Write one deploy to the local telemetry log, always.
     *
     * The live half of the pair: it lands the moment a deploy reaches a
     * terminal state, so `tail -f storage/logs/telemetry.log` shows an install
     * going wrong as it happens, over SSH, with no network and no ingest. The
     * spool is the delayed half and may be empty; this file may not.
     *
     * Shaped as the event envelope the panel and the monitoring ingest use
     * ({@see envelope()}), so the local record and anything sent later say the
     * same thing.
     *
     * @param array<string, mixed> $report
     */
    private static function record(array $report): void
    {
        try {
            $event = self::envelope($report);

            Log::channel('telemetry')->info(
                $event['type'] . ' ' . ($report['outcome'] ?? 'unknown'),
                $event
            );
        } catch (\Throwable $e) {
            // Losing the local line must not cost the caller anything either.
            Log::debug('Telemetry local record failed: ' . $e->getMessage());
        }
    }

    /**
     * A report as the shared event envelope.
     *
     * The type names the outcome, so counting failures is a count of one event
     * type, not a filter over a blended stream, and an alert binds to
     * the type instead of a boolean inside a payload.
     *
     * `status` still carries the exact outcome and `success` still marks only a
     * failure — both kept because consumers already test them.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    public static function envelope(array $report): array
    {
        $outcome = (string) ($report['outcome'] ?? '');
        $occurredAt = (int) ($report['occurred_at'] ?? time());

        return [
            'type' => self::eventType($outcome, self::kindOf($report)),
            'occurred_at' => gmdate('c', $occurredAt),
            // $report first: `+` keeps the left operand, so the report's own
            // values win and these are added only where it is silent. They are
            // derived for the ingest and must not mask what the report measured.
            'payload' => $report + self::derived($report, $outcome),
        ];
    }

    /**
     * What kind of thing this report is.
     *
     * A deploy report carries no `kind`: engines in the field have sent them
     * without one and the receiving end reads a report with no kind as a
     * deploy, so the absence is the answer.
     *
     * @param array<string, mixed> $report
     */
    private static function kindOf(array $report): string
    {
        $kind = $report['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? $kind : 'deploy';
    }

    /**
     * The fields the ingest is handed on top of the report itself.
     *
     * Per kind, because they answer that kind's question. A deploy report gets
     * `success`, which by the ingest spec marks only a failure; a bug report
     * does not, because a boolean saying one worked would be fabricated.
     *
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private static function derived(array $report, string $outcome): array
    {
        if (self::kindOf($report) === BugReport::KIND) {
            $bug = is_array($report['bug'] ?? null) ? $report['bug'] : [];

            return [
                'software_op' => 'bug_report',
                'status' => $outcome,
                'severity' => $bug['severity'] ?? null,
                'area' => $bug['area'] ?? null,
            ];
        }

        if (self::kindOf($report) === self::KIND_HEALTH) {
            $health = is_array($report['health'] ?? null) ? $report['health'] : [];

            return [
                'software_op' => 'health_check',
                'status' => $outcome,
                // No `success`: by the ingest spec that field marks a failed
                // deploy, and this one succeeded — the application stopped
                // serving afterwards, which is the distinction this event draws.
                'serving' => $health['serving'] ?? null,
                'severity' => self::worstSeverity($health['failed'] ?? []),
            ];
        }

        return [
            'software_op' => 'deploy',
            'status' => $outcome,
            'success' => $outcome !== DeployReport::OUTCOME_FAILED,
            'source' => $report['deploy']['source'] ?? null,
        ];
    }

    /**
     * The severity an alert should bind to: the worst any failing check
     * reported, so one error among four warnings does not read as a warning.
     *
     * @param mixed $failed
     */
    private static function worstSeverity(mixed $failed): ?string
    {
        $worst = null;
        foreach (is_array($failed) ? $failed : [] as $check) {
            $severity = is_array($check) ? ($check['severity'] ?? null) : null;
            if ($severity === 'error') {
                return 'error';
            }
            if ($severity === 'warning' || ($severity === 'info' && $worst === null)) {
                $worst = $severity === 'warning' ? 'warning' : 'info';
            }
        }

        return $worst;
    }

    /**
     * The event type one outcome is reported as.
     *
     * An unrecognised outcome reports as the bare family, not a guessed leaf:
     * an older ingest seeing `project.install` learns an install happened and
     * that this engine is newer, where guessing `success` would be fabricated.
     *
     * `cancelled` is named though never sent — the local log records every
     * outcome and a record that cannot name itself is worse.
     *
     * $kind short-circuits the table: a bug report has one type.
     */
    public static function eventType(string $outcome, string $kind = 'deploy'): string
    {
        if ($kind === BugReport::KIND) {
            return self::EVENT_BUG_REPORT;
        }

        if ($kind === self::KIND_HEALTH) {
            return self::EVENT_HEALTH;
        }

        return match ($outcome) {
            DeployReport::OUTCOME_SUCCESS => self::EVENT_SUCCESS,
            DeployReport::OUTCOME_FAILED => self::EVENT_FAIL,
            DeployReport::OUTCOME_PARTIAL => self::EVENT_PARTIAL,
            DeployReport::OUTCOME_RECOVERED => self::EVENT_RECOVERED,
            DeployReport::OUTCOME_CANCELLED => self::EVENT_CANCELLED,
            default => self::EVENT_UNKNOWN,
        };
    }

    /**
     * Something went wrong and the pipeline handled it.
     *
     * Leading indicators — a wrong port guess, a build that only fit after a
     * cache reclaim, a base image that fell back. None fails a deploy, so none
     * reaches support, and each one is a recipe that could be better.
     *
     * @param string $signal stable slug, e.g. 'port-realigned'
     */
    public static function signal(string $username, string $signal, string $detail = ''): void
    {
        try {
            // A malformed slug is a bug at the call site, not an event: nothing
            // worth recording locally or remotely.
            if (preg_match(self::SIGNAL_SLUG, $signal) !== 1) {
                return;
            }

            $fields = new TelemetryFields($username);
            $report = DeployReport::build(self::signalInput($fields, $signal, $detail));
            self::record($report);

            if (self::enabled() && self::installId() !== '' && $fields->isDindAccount()) {
                self::spool()->put($report);
            }
        } catch (\Throwable $e) {
            Log::debug('Telemetry signal failed: ' . $e->getMessage());
        }
    }

    /**
     * A periodic health sweep found an application that is up and is not
     * serving itself.
     *
     * What it adds over a deploy report is time: a deploy report is a
     * photograph of the moment an install finished, and this is the only thing
     * that says the site stopped working afterwards — a database that went
     * away, a lapsed certificate, files somebody deleted over SFTP.
     *
     * Sent only for an application with something wrong: a fleet-wide "still
     * fine" every six hours is the same fact four times a day per account.
     *
     * @param array<string, mixed> $details the account's details, health verdict included
     */
    public static function captureHealth(string $username, array $details): void
    {
        try {
            if (!self::enabled()) {
                return;
            }

            $fields = new TelemetryFields($username);
            // The kind is set here, not passed through the builder, the same
            // way BugReport sets its own: DeployReport describes a deploy and
            // knows nothing about the families layered over it.
            $report = DeployReport::build(self::healthInput($fields, $details));
            $report['kind'] = self::KIND_HEALTH;
            self::record($report);

            if (self::installId() !== '' && $fields->isDindAccount()) {
                self::spool()->put($report);
            }
        } catch (\Throwable $e) {
            Log::debug('Telemetry health capture failed: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private static function healthInput(TelemetryFields $fields, array $details): array
    {
        $serving = $details[AppHealth::DETAIL_SERVING] ?? null;

        return [
            'id' => (string) Str::ulid(),
            'occurred_at' => time(),
            'kind' => self::KIND_HEALTH,
            // Partial: the account is deployed and running, and is not serving
            // what it was deployed to serve. `failed` would claim the install
            // never worked, which is the case this exists to tell apart.
            'outcome' => DeployReport::OUTCOME_PARTIAL,
            'tier' => self::safely(fn (): int => self::tier(), DeployReport::TIER_METADATA),
            'install_id' => self::safely(fn (): string => self::installId(), ''),
            'username' => $fields->username,
            'latest' => self::safely(fn (): array => DeployLogger::readLatestFor($fields->username) ?? [], []),
            'details' => $details,
            'error' => '',
            // The check that fires first is the fingerprint: a stable slug, and
            // the same thing the failure `rule` means everywhere else here.
            'signal' => is_string($serving) && $serving !== '' ? 'health-' . $serving : 'health-degraded',
            // Read fresh, not repeated out of the deploy report: the point of
            // this event is that time has passed.
        ] + self::projectFacts($fields, $details);
    }

    /**
     * @return array<string, mixed>
     */
    private static function signalInput(TelemetryFields $fields, string $signal, string $detail): array
    {
        $details = $fields->accountDetails();

        return [
            'id' => (string) Str::ulid(),
            'occurred_at' => time(),
            'outcome' => DeployReport::OUTCOME_RECOVERED,
            'tier' => self::safely(fn (): int => self::tier(), DeployReport::TIER_METADATA),
            'install_id' => self::safely(fn (): string => self::installId(), ''),
            'username' => $fields->username,
            'latest' => self::safely(fn (): array => DeployLogger::readLatestFor($fields->username) ?? [], []),
            'details' => $details,
            'error' => $detail,
            'signal' => $signal,
            // One line about one event; the build log around it belongs to
            // whatever the deploy does next.
            'log_tail' => [],
        ] + self::projectFacts($fields, $details);
    }

    /**
     * Somebody is telling us the engine is wrong about one of their apps.
     *
     * The one entry point a person triggers on purpose, from the CLI, the REST
     * API or an MCP client, and therefore the one that reports failure instead
     * of swallowing it.
     *
     * **It is always about a project**: the engine inspects the application on
     * disk the way `project_inspect` does, probes every port it publishes the
     * way `app_health_check` does, and attaches its last deploy. The person
     * supplies only what went wrong and what they expected.
     *
     * Refusals, each an error instead of a silent no-op: `disabled` (telemetry
     * or bug reports off, so nothing would ship it), `invalid` (no project,
     * title or description), `no-project`, `not-ready` (no monitoring host configured, or
     * no fingerprint), `failed` (the spool could not be written).
     *
     * Only `queued` means it will be sent, by the next `telemetry:ship` run.
     * `dry_run` answers with the report and writes nothing — `preview`.
     *
     * @param array{
     *   title: string,
     *   description: string,
     *   severity?: ?string,
     *   area?: ?string,
     *   contact?: ?string,
     *   project: string,
     *   via?: ?string,
     *   attach_log?: bool,
     *   attach_health?: bool,
     *   dry_run?: bool
     * } $input
     *
     * @return array{
     *   status: string, queued: bool, id: ?string, reason: ?string,
     *   endpoint: string, report: ?array<string, mixed>
     * }
     */
    public static function captureBugReport(array $input): array
    {
        if (!self::enabled()) {
            return self::bugReportError(
                'disabled',
                'Telemetry is disabled on this install (TELEMETRY_ENABLED=false), so a bug report '
                . 'would never be sent. Enable it, or send storage/logs/telemetry-*.log to support instead.'
            );
        }

        if (!self::bugReportsEnabled()) {
            return self::bugReportError(
                'disabled',
                'Bug reports are disabled on this install (TELEMETRY_BUG_REPORTS=false).'
            );
        }

        if (trim((string) ($input['title'] ?? '')) === '' || trim((string) ($input['description'] ?? '')) === '') {
            return self::bugReportError(
                'invalid',
                'A bug report needs both a title and a description.'
            );
        }

        $username = trim((string) ($input['project'] ?? ''));
        if ($username === '') {
            return self::bugReportError(
                'invalid',
                'A bug report is about one application: name the project it concerns.'
            );
        }

        $endpoint = self::safely(fn (): string => self::endpoint(), '');
        if ($endpoint === '') {
            return self::bugReportError(
                'not-ready',
                'No monitoring host is configured (PANELALPHA_MONITORING is empty), '
                . 'so there is nowhere to send a bug report.'
            );
        }

        $installId = self::safely(fn (): string => self::installId(), '');
        if ($installId === '') {
            return self::bugReportError(
                'not-ready',
                'This install could not derive its install id, so a report would arrive unattributable.'
            );
        }

        // Last of the refusals, because it is the first that costs anything:
        // everything above is a config read, and this is a database lookup
        // leading to a directory walk and a probe inside a container.
        //
        // Direct lookup, not TelemetryFields, which swallows a failed query as
        // "no such account": a database that is down would tell somebody their
        // project does not exist.
        try {
            $user = User::findByUsername($username);
        } catch (\Throwable $e) {
            return self::bugReportError(
                'failed',
                'Could not look the project up: ' . $e->getMessage()
            );
        }

        if ($user === null) {
            return self::bugReportError(
                'no-project',
                "No project named '{$username}' on this install."
            );
        }

        $fields = new TelemetryFields($username);

        try {
            $report = BugReport::build(self::bugReportInput($input, $fields, $installId));
        } catch (\Throwable $e) {
            Log::debug('Telemetry bug report could not be built: ' . $e->getMessage());

            return self::bugReportError('failed', 'The bug report could not be assembled: ' . $e->getMessage());
        }

        // A preview stops here, having written nothing: it exists so somebody
        // can read the exact bytes before agreeing to send them, and a copy in
        // the local log would have sent half of what it was asked to show.
        if (($input['dry_run'] ?? false) === true) {
            return [
                'status' => 'preview',
                'queued' => false,
                'id' => (string) $report['id'],
                'reason' => null,
                'endpoint' => $endpoint,
                'report' => $report,
            ];
        }

        // Written down before it is queued, like a deploy report: the local log
        // survives a spool the engine fails to drain, and is what an operator
        // hands to support by hand.
        self::record($report);

        if (self::spool()->put($report) === null) {
            return [
                'status' => 'failed',
                'queued' => false,
                'id' => (string) $report['id'],
                'reason' => 'The report could not be written to the telemetry spool at '
                    . self::spool()->dir() . ' — check free disk space and permissions.',
                'endpoint' => $endpoint,
                'report' => $report,
            ];
        }

        return [
            'status' => 'queued',
            'queued' => true,
            'id' => (string) $report['id'],
            'reason' => null,
            'endpoint' => $endpoint,
            'report' => $report,
        ];
    }

    /**
     * @return array{status: string, queued: bool, id: null, reason: string, endpoint: string, report: null}
     */
    private static function bugReportError(string $status, string $reason): array
    {
        return [
            'status' => $status,
            'queued' => false,
            'id' => null,
            'reason' => $reason,
            'endpoint' => self::safely(fn (): string => self::endpoint(), ''),
            'report' => null,
        ];
    }

    /**
     * What a bug report is made of, in increasing order of cost:
     * `details`/`latest` (the account's frozen deploy snapshot, two file
     * reads), `inspect` (what `project_inspect` answers with — whether the
     * engine understood the application at all), `log_tail` (the end of the
     * deploy log at tier 2) and `health` (a live probe of every published port
     * from inside the container, the only part with its own opt-out).
     *
     * Every one is best-effort: a project rolled back after a failed deploy has
     * nothing to inspect and a wedged container cannot be probed, and neither
     * is a reason to lose the report a person just wrote.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private static function bugReportInput(array $input, TelemetryFields $fields, string $installId): array
    {
        $username = $fields->username;
        $tier = self::safely(fn (): int => self::tier(), DeployReport::TIER_METADATA);

        $logger = ($input['attach_log'] ?? true) === false
            ? null
            : TelemetryFields::safely(fn (): ?DeployLogger => DeployLogger::current($username), null);

        return [
            'id' => (string) Str::ulid(),
            'occurred_at' => time(),
            'tier' => $tier,
            'install_id' => $installId,
            'username' => $username,
            'title' => (string) ($input['title'] ?? ''),
            'description' => (string) ($input['description'] ?? ''),
            'severity' => self::stringOrNull($input['severity'] ?? null),
            'area' => self::stringOrNull($input['area'] ?? null),
            'contact' => self::stringOrNull($input['contact'] ?? null),
            'via' => self::stringOrNull($input['via'] ?? null),
            'details' => $fields->accountDetails(),
            'repo_private' => $fields->repoIsPrivate(),
            // The address the reporter is looking at, so the report says where
            // the problem is. This path does not go through projectFacts(),
            // which is where every other family picks it up.
            'domains' => $fields->domains(),
            'latest' => self::safely(fn (): array => DeployLogger::readLatestFor($username) ?? [], []),
            'inspect' => self::inspectApp($fields),
            'health' => ($input['attach_health'] ?? true) === false ? null : self::probeApp($fields),
            'log_tail' => $logger === null ? [] : $fields->logTail($logger, self::LOG_TAIL_LINES),
        ];
    }

    /**
     * What the application on disk is, as `project_inspect` would report it.
     *
     * Runs the deploy pipeline's own detection over the project directory, so
     * this is what the *next* deploy would decide, not a second opinion
     * about the last one — and `drift` is the two compared, which is often the
     * answer to "why is it serving the old thing".
     *
     * @return ?array<string, mixed>
     */
    private static function inspectApp(TelemetryFields $fields): ?array
    {
        return TelemetryFields::safely(function () use ($fields): ?array {
            $user = $fields->user();
            if ($user === null) {
                return null;
            }

            $report = AppInspector::inspect($fields->projectDir(), $fields->repoUrl());
            $details = $fields->accountDetails();

            // The two sections the project inspect endpoint adds for an account:
            // what its last deploy froze, and how the files moved on from it.
            return [
                'deployment' => DeploymentSnapshot::describe($details),
                'drift' => DeploymentSnapshot::drift($details, $report, null),
            ] + $report;
        }, null);
    }

    /**
     * Whether the application is answering, right now.
     *
     * The same probe `app_health_check` runs: every published port, from inside
     * the container, over loopback — so a failure here is the application, not
     * DNS, TLS or the proxy. `checks` asks whether what answered *is* the
     * application, which is the case a person files a bug about.
     *
     * One attempt, not the endpoint's three: nobody is waiting on a retry loop,
     * and a port that needs three tries is itself worth reporting.
     *
     * @return ?array<string, mixed>
     */
    private static function probeApp(TelemetryFields $fields): ?array
    {
        return TelemetryFields::safely(function () use ($fields): ?array {
            $user = $fields->user();
            // The classic template has no container to probe: not an error and
            // not a failure, simply nothing there to ask.
            if ($user === null || !$fields->isDindAccount()) {
                return null;
            }

            $runtime = $user->project()->runtime();
            if (!$runtime instanceof Dind) {
                return null;
            }

            return $runtime->appHealth()->check(self::HEALTH_TIMEOUT, 1);
        }, null);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Zip the account's source, if the operator has asked for that.
     *
     * Writes the archive next to the report in the spool and returns the
     * descriptor for the report. Null when bundling is off or this failure is
     * not a candidate, so the field is absent, not false, on every report an
     * install that never bundles sends.
     *
     * A line goes into the deploy log whenever a bundle is taken, because the
     * customer is entitled to see that a copy of their code was made.
     *
     * @return ?array<string, mixed>
     */
    private static function captureSourceBundle(
        DeployLogger $logger,
        string $username,
        string $outcome,
        ?string $error,
        string $reportId
    ): ?array {
        if (!self::shouldBundle($outcome, $error)) {
            return null;
        }

        $spool = self::spool();
        $zipPath = $spool->bundlePathFor($reportId);
        if ($zipPath === null || !$spool->ensureDirectory()) {
            return null;
        }

        $result = SourceBundle::create(
            (new TelemetryFields($username))->projectDir(),
            $zipPath,
            (int) config('telemetry.source_bundle.max_bytes', 25 * 1024 * 1024),
            (int) config('telemetry.source_bundle.max_files', 5000),
            (int) config('telemetry.source_bundle.max_file_bytes', 2 * 1024 * 1024)
        );

        // SourceBundle writes the zip as the current uid (often root via pae);
        // cron ships as www-data and must be able to read and delete it.
        if (is_file($zipPath)) {
            $spool->claimOwnership($zipPath);
        }

        if ($result['available']) {
            $logger->info(sprintf(
                'Captured a source bundle for diagnostics (%d files, %s) — secrets, .env files and dependencies excluded',
                $result['files'],
                self::humanBytes((int) $result['bytes'])
            ));
        }

        return $result;
    }

    /** Bundling is off, or this failure is not a candidate for it. */
    private static function shouldBundle(string $outcome, ?string $error): bool
    {
        $mode = self::sourceBundleMode();
        if ($mode === SourceBundlePolicy::MODE_OFF) {
            return false;
        }
        $rule = DeployFailureExplainer::match((string) $error)['rule'] ?? null;

        return SourceBundlePolicy::shouldCapture($mode, $outcome, $rule);
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KiB';
        }

        return round($bytes / 1024 / 1024, 1) . ' MiB';
    }
}
