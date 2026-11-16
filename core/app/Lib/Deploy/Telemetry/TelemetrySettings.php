<?php

namespace App\Lib\Deploy\Telemetry;

use App\Models\Setting;
use App\Support\EngineSetting;
use App\Support\EnvFile;

/**
 * What this engine reports about itself, for `pae configure telemetry` and the API alike.
 *
 * Sending is the `telemetry_enabled` setting, which overrides TELEMETRY_ENABLED and is synced to monitoring when it
 * changes. What a report carries, source bundles and bug reports are {@see EngineSetting}s over TELEMETRY_TIER,
 * TELEMETRY_SOURCE_BUNDLE and TELEMETRY_BUG_REPORTS.
 */
class TelemetrySettings
{
    public const TIERS = [
        'metadata' => DeployReport::TIER_METADATA,
        'repo' => DeployReport::TIER_REPO,
        'log' => DeployReport::TIER_LOG,
    ];

    public function __construct(private readonly ?EnvFile $env = null) {}

    public static function tierSetting(): EngineSetting
    {
        return new EngineSetting('TELEMETRY_TIER', 'telemetry.tier');
    }

    public static function sourceBundleSetting(): EngineSetting
    {
        return new EngineSetting('TELEMETRY_SOURCE_BUNDLE', 'telemetry.source_bundle.mode');
    }

    public static function bugReportsSetting(): EngineSetting
    {
        return new EngineSetting('TELEMETRY_BUG_REPORTS', 'telemetry.bug_reports.enabled');
    }

    /** The stored preference sending follows; Telemetry::enabled() ignores it under test. */
    public function sending(): bool
    {
        return NotificationPreferences::isTelemetryEnabled();
    }

    /**
     * Turns sending on or off and tells monitoring, as `telemetry:enable` and `telemetry:disable` do. The local
     * switch stands even when monitoring cannot be told.
     *
     * @return array{ok: bool, message: string}
     */
    public function setSending(bool $on): array
    {
        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, $on ? '1' : '0');

        return NotificationPreferences::sync($on);
    }

    public function tier(): int
    {
        return Telemetry::tier();
    }

    public function sourceBundle(): string
    {
        return Telemetry::sourceBundleMode();
    }

    public function bugReports(): bool
    {
        return filter_var(self::bugReportsSetting()->value() ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public function setTier(?int $tier): bool
    {
        return self::tierSetting()->set($tier === null ? null : (string) $tier);
    }

    public function setSourceBundle(?string $mode): bool
    {
        return self::sourceBundleSetting()->set($mode);
    }

    public function setBugReports(?bool $allowed): bool
    {
        return self::bugReportsSetting()->set($allowed === null ? null : ($allowed ? 'true' : 'false'));
    }

    /**
     * Applies each given field; one left out stays as it is, null puts it back to `.env-core` or the default.
     * Sending is changed last, so monitoring is told about what the rest now says, and only when it changes; an
     * unchanged one is stored as given all the same.
     *
     * @param  array{sending?: bool, tier?: ?string, source_bundle?: ?string, bug_reports?: ?bool}  $changes
     * @return array{ok: bool, message: string}|null how telling monitoring went, null when sending did not change
     */
    public function set(array $changes): ?array
    {
        if (array_key_exists('tier', $changes)) {
            $this->setTier($changes['tier'] === null ? null : self::TIERS[$changes['tier']]);
        }
        if (array_key_exists('source_bundle', $changes)) {
            $this->setSourceBundle($changes['source_bundle']);
        }
        if (array_key_exists('bug_reports', $changes)) {
            $this->setBugReports($changes['bug_reports']);
        }

        return array_key_exists('sending', $changes) ? $this->applySending($changes['sending']) : null;
    }

    /**
     * @return array{
     *     sending: array{value: bool, source: string},
     *     tier: array{value: string, source: string},
     *     source_bundle: array{value: string, source: string},
     *     bug_reports: array{value: bool, source: string},
     *     queued: int,
     *     endpoint: ?string
     * }
     */
    public function toArray(): array
    {
        $endpoint = Telemetry::endpoint();

        return [
            'sending' => ['value' => $this->sending(), 'source' => $this->sendingSource()],
            'tier' => ['value' => (string) array_search($this->tier(), self::TIERS, true), 'source' => self::tierSetting()->source($this->env)],
            'source_bundle' => ['value' => $this->sourceBundle(), 'source' => self::sourceBundleSetting()->source($this->env)],
            'bug_reports' => ['value' => $this->bugReports(), 'source' => self::bugReportsSetting()->source($this->env)],
            'queued' => (int) Telemetry::spool()->stats()['count'],
            'endpoint' => $endpoint === '' ? null : $endpoint,
        ];
    }

    /** @return array{ok: bool, message: string}|null */
    private function applySending(bool $on): ?array
    {
        if ($on !== $this->sending()) {
            return $this->setSending($on);
        }

        Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, $on ? '1' : '0');

        return null;
    }

    private function sendingSource(): string
    {
        return (new EngineSetting('TELEMETRY_ENABLED', 'telemetry.enabled'))->source($this->env);
    }
}
