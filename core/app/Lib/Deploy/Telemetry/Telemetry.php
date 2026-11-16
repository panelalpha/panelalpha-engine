<?php

namespace App\Lib\Deploy\Telemetry;

use App\Integrations\Monitoring\PanelAlphaMonitoring;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\DeployStatus;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\DeploymentSnapshot;
use App\Lib\Findings\Finding;
use App\Models\User;
use App\System;
use App\System\Project\Dind;
use App\System\Project\Dind\AppHealth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Deploy telemetry through `captureDeploy()`, `signal()`, `captureHealth()` and `captureBugReport()`. None may throw,
 * so each failure is a debug line, except a bug report, which answers an install that cannot ship it with an error.
 */
class Telemetry
{
    /**
     * One event type per outcome under `project.`, the name the API, CLI and docs give a hosting account;
     * installs of any kind are a prefix match and failures an exact one.
     */
    public const EVENT_SUCCESS = 'project.install.success';

    public const EVENT_FAIL = 'project.install.fail';

    public const EVENT_PARTIAL = 'project.install.partial';

    public const EVENT_RECOVERED = 'project.install.recovered';

    public const EVENT_CANCELLED = 'project.install.cancelled';

    /** An outcome no version of this engine knows how to name. */
    public const EVENT_UNKNOWN = 'project.install';

    /**
     * A bug somebody reported by hand: its own family, as nothing was deployed and a leaf under
     * `project.install.` would land in every query that counts installs.
     */
    public const EVENT_BUG_REPORT = 'support.bug_report';

    /**
     * An application up and not serving itself, found by the periodic sweep: its own family, as nothing was
     * installed and `recovered` would claim something was put right.
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

    /**
     * Under test, only the config a test sets: on an installed host the operator's stored `telemetry:enable`
     * would override phpunit.xml's TELEMETRY_ENABLED=false.
     */
    public static function enabled(): bool
    {
        if (self::underTest()) {
            return (bool) config('telemetry.enabled', false);
        }

        return NotificationPreferences::isTelemetryEnabled();
    }

    private static function underTest(): bool
    {
        return app()->runningUnitTests();
    }

    /**
     * Whether an operator may file a bug report from this install: a second switch under the first, as anonymous
     * deploy statistics are not a free-text field leaving the box.
     */
    public static function bugReportsEnabled(): bool
    {
        return self::enabled() && (new TelemetrySettings)->bugReports();
    }

    /**
     * The ingest's shared event route for a batch of reports. Config carries this constant and the env overrides it,
     * so the fallback cannot disagree with the config it stands in for.
     */
    public const EVENTS_PATH = '/api/v1/events';

    /**
     * The configured route, or this engine's own answer when nothing set one: a blank override means "unset",
     * not "post at the host root", which would send reports to a web page.
     */
    public static function eventsPath(): string
    {
        $configured = trim((string) config('telemetry.reports_path', ''));

        return $configured !== '' ? $configured : self::EVENTS_PATH;
    }

    /**
     * Where a batch of reports is POSTed on {@see PanelAlphaMonitoring}, or '' when nothing is configured;
     * Connect is an integration the engine calls to get something done, and answered telemetry 405.
     */
    public static function endpoint(): string
    {
        return PanelAlphaMonitoring::url(self::eventsPath());
    }

    public static function tier(): int
    {
        return DeployReport::clampTier((int) (TelemetrySettings::tierSetting()->value() ?? DeployReport::TIER_LOG));
    }

    /**
     * Source-bundle mode: `off`, `unexplained` or `failed`. Not a fourth tier: a bundle is the customer's
     * intellectual property, and raising the tier must never turn it on.
     */
    public static function sourceBundleMode(): string
    {
        return SourceBundlePolicy::normalizeMode(
            (string) (TelemetrySettings::sourceBundleSetting()->value() ?? SourceBundlePolicy::MODE_OFF)
        );
    }

    public static function spool(): Spool
    {
        return self::$spool ??= new Spool(
            (string) config('telemetry.spool_dir', storage_path('app/telemetry/outbox'))
        );
    }

    /**
     * The pinned install id, or one derived from machine facts and pinned; '' when neither is possible, so the
     * shipper holds reports rather than send them under an id every unprobeable install shares.
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
            $id = InstallFingerprint::derive(HostFacts::forFingerprint(new System));
        } catch (\Throwable $e) {
            Log::debug('Telemetry could not derive an install id: '.$e->getMessage());
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
     * A deploy finished, from {@see DeployLogger::finish()} before it publishes `$latest`. A failure is sent at once
     * and monitoring's fix returned; anything else, or a send that does not get through, goes to the spool.
     *
     * @param  array<string, mixed>  $latest  the deploy's final status record
     * @return array<string, ?string>|null the fix, see {@see TelemetryShipper::problem()}
     */
    public static function captureDeploy(
        DeployLogger $logger,
        string $username,
        string $outcome,
        ?string $error,
        array $latest
    ): ?array {
        try {
            $fields = new TelemetryFields($username);
            $reportId = (string) Str::ulid();
            $input = self::deployInput($fields, $logger, $outcome, $error, $reportId, $latest);
            $sendable = self::isSendable($fields, $outcome, $input['latest']);
            $bundle = $sendable ? self::captureSourceBundle($logger, $username, $outcome, $error, $reportId) : null;
            if ($bundle !== null) {
                $input['source_bundle'] = $bundle;
            }

            $report = DeployReport::build($input);

            self::record($report);

            if (! $sendable) {
                return null;
            }

            return self::sendOrSpool($report, $outcome, isset($input['source_bundle']));
        } catch (\Throwable $e) {
            Log::debug('Telemetry capture failed: '.$e->getMessage());
        }

        return null;
    }

    /**
     * A failure is sent at once, unless it carries source: then the server may ask for the bundle, and uploading
     * it does not belong on the deploy's path.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, ?string>|null
     */
    private static function sendOrSpool(array $report, string $outcome, bool $hasBundle): ?array
    {
        if ($outcome === DeployReport::OUTCOME_FAILED && ! $hasBundle) {
            $sent = (new TelemetryShipper)->sendNow($report);
            if ($sent['sent']) {
                return $sent['problem'];
            }
        }

        self::spool()->put($report);

        return null;
    }

    /**
     * `latest` is passed in, as latest.json still says `running` at this point.
     *
     * @param  array<string, mixed>  $latest
     * @return array<string, mixed>
     */
    private static function deployInput(
        TelemetryFields $fields,
        DeployLogger $logger,
        string $outcome,
        ?string $error,
        string $reportId,
        array $latest
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
            'latest' => $latest,
            'details' => $details,
            'error' => $error,
            'signal' => self::healthSignal($outcome, $details) ?? self::warningSignal($outcome, $latest),
            'log_tail' => self::deployLogTail($fields, $logger, $tier, $outcome),
        ] + self::projectFacts($fields, $details);
    }

    /**
     * None on a clean success: successes are most of the traffic and their build output answers nothing.
     *
     * @return list<string>
     */
    private static function deployLogTail(TelemetryFields $fields, DeployLogger $logger, int $tier, string $outcome): array
    {
        return $tier >= DeployReport::TIER_LOG && $outcome !== DeployReport::OUTCOME_SUCCESS
            ? $fields->logTail($logger, self::LOG_TAIL_LINES)
            : [];
    }

    /**
     * Everything that stops a report being sent, though never written down; a precheck refusal is no deploy
     * failure, as the host failed the app's requirements before anything was deployed.
     *
     * @param  array<string, mixed>  $latest
     */
    private static function isSendable(TelemetryFields $fields, string $outcome, array $latest): bool
    {
        return ! DeployReport::isPreCheckRejection($latest)
            && self::enabled()
            && DeployReport::isReportable($outcome)
            && self::safely(fn (): string => self::installId(), '') !== ''
            && $fields->isDindAccount();
    }

    /**
     * A partial deploy is grouped by the code of its first warning, which stays the same whatever its sentence says.
     *
     * @param  array<string, mixed>  $latest
     */
    private static function warningSignal(string $outcome, array $latest): ?string
    {
        if ($outcome !== DeployReport::OUTCOME_PARTIAL) {
            return null;
        }
        $first = DeployStatus::warningsOf($latest)[0] ?? null;

        return $first !== null && $first->code !== Finding::UNCLASSIFIED ? $first->code : null;
    }

    /**
     * The sweep's slug for a deploy left partial by a failing error check, so the fact groups the same whenever it
     * was noticed; anything else falls through to {@see DeployFailureExplainer}.
     *
     * @param  array<string, mixed>  $details
     */
    private static function healthSignal(string $outcome, array $details): ?string
    {
        if ($outcome !== DeployReport::OUTCOME_PARTIAL) {
            return null;
        }

        if (AppHealth::errorCheckFindings($details) === []) {
            return null;
        }

        $serving = $details[AppHealth::DETAIL_SERVING] ?? null;

        return is_string($serving) && $serving !== '' ? 'health-'.$serving : null;
    }

    /**
     * What this account's project is, in the one block every event family states, read off the tree at the event:
     * a failed deploy is rolled back moments later. {@see DeployReport} decides what may be sent at which tier.
     *
     * @param  array<string, mixed>  $details  the account's details
     * @return array<string, mixed>
     */
    private static function projectFacts(TelemetryFields $fields, array $details): array
    {
        return [
            'repo_url' => $fields->repoUrl(),
            'repo_private' => $fields->repoIsPrivate(),
            'repo_source' => $fields->repoSource(),
            'checkout' => $fields->checkout(),
            'manifests' => $fields->manifests(),
            'candidates' => $fields->candidates(),
            'packages' => $fields->packages(self::runtimeOf($details)),
            'domains' => $fields->domains(),
            'server_names' => $fields->serverNames(),
        ];
    }

    /**
     * The ecosystem the deploy chose to build, so metadata reports on that half
     * of a polyglot project, not on whichever reader sorts first.
     *
     * @param  array<string, mixed>  $details
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
     *
     * @param  callable(): T  $value
     * @param  T  $default
     * @return T
     */
    private static function safely(callable $value, mixed $default): mixed
    {
        return TelemetryFields::safely($value, $default);
    }

    /**
     * Write one report to the local telemetry log as its {@see envelope()}, except under test, where fixtures would
     * land in an operator's log. The live half an operator can `tail -f`; losing the line costs the caller nothing.
     *
     * @param  array<string, mixed>  $report
     */
    private static function record(array $report): void
    {
        if (self::underTest()) {
            return;
        }

        try {
            $event = self::envelope($report);

            Log::channel('telemetry')->info(
                $event['type'].' '.($report['outcome'] ?? 'unknown'),
                $event
            );
        } catch (\Throwable $e) {
            Log::debug('Telemetry local record failed: '.$e->getMessage());
        }
    }

    /**
     * A report as the shared event envelope, typed by its outcome. The payload is the report first: `+` keeps the
     * left operand, so the derived fields are added only where the report is silent.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function envelope(array $report): array
    {
        $outcome = (string) ($report['outcome'] ?? '');
        $occurredAt = (int) ($report['occurred_at'] ?? time());

        return [
            'type' => self::eventType($outcome, self::kindOf($report)),
            'occurred_at' => gmdate('c', $occurredAt),
            'payload' => $report + self::derived($report, $outcome),
        ];
    }

    /**
     * What kind of thing this report is; a deploy report carries no `kind`, and the receiving end reads its
     * absence as a deploy.
     *
     * @param  array<string, mixed>  $report
     */
    private static function kindOf(array $report): string
    {
        $kind = $report['kind'] ?? null;

        return is_string($kind) && $kind !== '' ? $kind : 'deploy';
    }

    /**
     * The fields the ingest is handed on top of the report, per kind. Only a deploy gets `success`, which marks
     * a failed deploy: for a bug report or a health finding it would be fabricated.
     *
     * @param  array<string, mixed>  $report
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
     * The event type one outcome is reported as; $kind short-circuits the table. An unknown outcome is the bare
     * family, not a guessed leaf, and `cancelled` is named though never sent, for the local log.
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
     * Something went wrong and the pipeline handled it, such as a wrong port guess: a recipe that could be better.
     * A malformed slug is a bug at the call site, recorded nowhere.
     *
     * @param  string  $signal  stable slug, e.g. 'port-realigned'
     */
    public static function signal(string $username, string $signal, string $detail = ''): void
    {
        try {
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
            Log::debug('Telemetry signal failed: '.$e->getMessage());
        }
    }

    /**
     * A periodic sweep found an application up and not serving itself, sent only when something is wrong. The kind
     * is set on the built report, as BugReport sets its own: DeployReport knows nothing of the families over it.
     *
     * @param  array<string, mixed>  $details  the account's details, health verdict included
     */
    public static function captureHealth(string $username, array $details): void
    {
        try {
            if (! self::enabled()) {
                return;
            }

            $fields = new TelemetryFields($username);
            $report = DeployReport::build(self::healthInput($fields, $details));
            $report['kind'] = self::KIND_HEALTH;
            self::record($report);

            if (self::installId() !== '' && $fields->isDindAccount()) {
                self::spool()->put($report);
            }
        } catch (\Throwable $e) {
            Log::debug('Telemetry health capture failed: '.$e->getMessage());
        }
    }

    /**
     * Partial, as the account is deployed and not serving what it was deployed to serve, signed by the first
     * failing check; the project facts are read fresh, since time has passed.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function healthInput(TelemetryFields $fields, array $details): array
    {
        $serving = $details[AppHealth::DETAIL_SERVING] ?? null;

        return [
            'id' => (string) Str::ulid(),
            'occurred_at' => time(),
            'kind' => self::KIND_HEALTH,
            'outcome' => DeployReport::OUTCOME_PARTIAL,
            'tier' => self::safely(fn (): int => self::tier(), DeployReport::TIER_METADATA),
            'install_id' => self::safely(fn (): string => self::installId(), ''),
            'username' => $fields->username,
            'latest' => self::safely(fn (): array => DeployLogger::readLatestFor($fields->username) ?? [], []),
            'details' => $details,
            'error' => '',
            'signal' => is_string($serving) && $serving !== '' ? 'health-'.$serving : 'health-degraded',
        ] + self::projectFacts($fields, $details);
    }

    /**
     * No log tail: one line about one event, and the build log around it belongs to what the deploy does next.
     *
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
            'log_tail' => [],
        ] + self::projectFacts($fields, $details);
    }

    /**
     * Somebody reports the engine is wrong about one of their apps: the one entry point that answers failure
     * instead of swallowing it. Only `queued` will be sent, by the next `telemetry:ship`; `dry_run` is `preview`.
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
     * @return array{
     *   status: string, queued: bool, id: ?string, reason: ?string,
     *   endpoint: string, report: ?array<string, mixed>
     * }
     */
    public static function captureBugReport(array $input): array
    {
        $refusal = self::bugReportInputRefusal($input);
        if ($refusal !== null) {
            return $refusal;
        }

        $username = trim((string) ($input['project'] ?? ''));
        if ($username === '') {
            return self::bugReportError('invalid', 'A bug report is about one application: name the project it concerns.');
        }

        $endpoint = self::safely(fn (): string => self::endpoint(), '');
        if ($endpoint === '') {
            return self::bugReportError(
                'not-ready',
                'No monitoring host is configured (PANELALPHA_MONITORING is empty), '
                .'so there is nowhere to send a bug report.'
            );
        }

        $installId = self::safely(fn (): string => self::installId(), '');
        if ($installId === '') {
            return self::bugReportError('not-ready', 'This install could not derive its install id, so a report would arrive unattributable.');
        }

        return self::fileBugReport($input, $username, $installId, $endpoint);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return ?array{status: string, queued: bool, id: null, reason: string, endpoint: string, report: null}
     */
    private static function bugReportInputRefusal(array $input): ?array
    {
        if (! self::enabled()) {
            return self::bugReportError(
                'disabled',
                'Telemetry is disabled on this install (TELEMETRY_ENABLED=false), so a bug report '
                .'would never be sent. Enable it, or send storage/logs/telemetry-*.log to support instead.'
            );
        }

        if (! self::bugReportsEnabled()) {
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

        return null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status: string, queued: bool, id: ?string, reason: ?string, endpoint: string, report: ?array<string, mixed>}
     */
    private static function fileBugReport(array $input, string $username, string $installId, string $endpoint): array
    {
        $refusal = self::projectRefusal($username);
        if ($refusal !== null) {
            return $refusal;
        }

        $fields = new TelemetryFields($username);

        try {
            $report = BugReport::build(self::bugReportInput($input, $fields, $installId));
        } catch (\Throwable $e) {
            Log::debug('Telemetry bug report could not be built: '.$e->getMessage());

            return self::bugReportError('failed', 'The bug report could not be assembled: '.$e->getMessage());
        }

        return self::deliverBugReport($input, $report, $endpoint);
    }

    /**
     * Last of the refusals, as the first that costs anything. A direct lookup: TelemetryFields reads a failed
     * query as "no such account", which would tell somebody their project does not exist.
     *
     * @return ?array{status: string, queued: bool, id: null, reason: string, endpoint: string, report: null}
     */
    private static function projectRefusal(string $username): ?array
    {
        try {
            $user = User::findByUsername($username);
        } catch (\Throwable $e) {
            return self::bugReportError(
                'failed',
                'Could not look the project up: '.$e->getMessage()
            );
        }

        if ($user === null) {
            return self::bugReportError(
                'no-project',
                "No project named '{$username}' on this install."
            );
        }

        return null;
    }

    /**
     * A preview writes nothing, so somebody can read the exact bytes before agreeing to send them. Otherwise the
     * report is written to the local log before it is queued, which survives a spool the engine fails to drain.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $report
     * @return array{status: string, queued: bool, id: string, reason: ?string, endpoint: string, report: array<string, mixed>}
     */
    private static function deliverBugReport(array $input, array $report, string $endpoint): array
    {
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

        self::record($report);

        return self::queueBugReport($report, $endpoint);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array{status: string, queued: bool, id: string, reason: ?string, endpoint: string, report: array<string, mixed>}
     */
    private static function queueBugReport(array $report, string $endpoint): array
    {
        if (self::spool()->put($report) === null) {
            return [
                'status' => 'failed',
                'queued' => false,
                'id' => (string) $report['id'],
                'reason' => 'The report could not be written to the telemetry spool at '
                    .self::spool()->dir().' — check free disk space and permissions.',
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
     * What a bug report is made of, cheapest first, each part best-effort so none can lose the report. It names `domains`
     * and `server_names` itself, as this path does not go through projectFacts(), where every other family picks them up.
     *
     * @param  array<string, mixed>  $input
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
            'repo_url' => $fields->repoUrl(),
            'repo_private' => $fields->repoIsPrivate(),
            'domains' => $fields->domains(),
            'server_names' => $fields->serverNames(),
            'latest' => self::safely(fn (): array => DeployLogger::readLatestFor($username) ?? [], []),
            'inspect' => self::inspectApp($fields),
            'health' => ($input['attach_health'] ?? true) === false ? null : self::probeApp($fields),
            'log_tail' => $logger === null ? [] : $fields->logTail($logger, self::LOG_TAIL_LINES),
        ];
    }

    /**
     * What the next deploy would decide about the application on disk, as `project_inspect` reports it, with the
     * two sections it adds for an account: what the last deploy froze, and the drift since.
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

            return [
                'deployment' => DeploymentSnapshot::describe($details),
                'drift' => DeploymentSnapshot::drift($details, $report, null),
            ] + $report;
        }, null);
    }

    /**
     * Whether the application answers, by `app_health_check`'s loopback probe in one attempt, not three.
     * Null for the classic template, which has no container to probe.
     *
     * @return ?array<string, mixed>
     */
    private static function probeApp(TelemetryFields $fields): ?array
    {
        return TelemetryFields::safely(function () use ($fields): ?array {
            $user = $fields->user();
            if ($user === null || ! $fields->isDindAccount()) {
                return null;
            }

            $runtime = $user->project()->runtime();
            if (! $runtime instanceof Dind) {
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
     * Zip the account's source if the operator asked, at capture rather than at ship time: a failed creation is rolled back
     * the moment captureDeploy() returns. A taken bundle is logged, as the customer may see a copy was made.
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
        if (! self::shouldBundle($outcome, $error)) {
            return null;
        }

        $spool = self::spool();
        $zipPath = $spool->bundlePathFor($reportId);
        if ($zipPath === null || ! $spool->ensureDirectory()) {
            return null;
        }

        $result = self::createSourceBundle($username, $zipPath);
        self::handBundleToCron($spool, $zipPath);
        if ($result['available']) {
            self::announceSourceBundle($logger, $result);
        }

        return $result;
    }

    /**
     * @return array{available: bool, files?: int, bytes?: int, sha256?: string, skipped?: string}
     */
    private static function createSourceBundle(string $username, string $zipPath): array
    {
        return SourceBundle::create(
            (new TelemetryFields($username))->projectDir(),
            $zipPath,
            (int) config('telemetry.source_bundle.max_bytes', 25 * 1024 * 1024),
            (int) config('telemetry.source_bundle.max_files', 5000),
            (int) config('telemetry.source_bundle.max_file_bytes', 2 * 1024 * 1024)
        );
    }

    /** SourceBundle writes the zip as the current uid (often root via pae); cron ships it as www-data. */
    private static function handBundleToCron(Spool $spool, string $zipPath): void
    {
        if (is_file($zipPath)) {
            $spool->claimOwnership($zipPath);
        }
    }

    /**
     * @param  array{available: bool, files?: int, bytes?: int, sha256?: string, skipped?: string}  $result
     */
    private static function announceSourceBundle(DeployLogger $logger, array $result): void
    {
        $logger->info(sprintf(
            'Captured a source bundle for diagnostics (%d files, %s) — secrets, .env files and dependencies excluded',
            $result['files'],
            self::humanBytes((int) $result['bytes'])
        ));
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
            return round($bytes / 1024, 1).' KiB';
        }

        return round($bytes / 1024 / 1024, 1).' MiB';
    }
}
