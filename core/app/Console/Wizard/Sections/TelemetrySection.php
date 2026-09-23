<?php

namespace App\Console\Wizard\Sections;

use App\Console\Prompts\Screen;
use App\Console\Wizard\KeepsAReceipt;
use App\Console\Wizard\Section;
use App\Lib\Deploy\Telemetry\DeployReport;
use App\Lib\Deploy\Telemetry\NotificationPreferences;
use App\Lib\Deploy\Telemetry\SourceBundlePolicy;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\Models\Setting;
use App\Support\EnvFile;
use Illuminate\Support\Facades\Artisan;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\note;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\select;
use function Laravel\Prompts\warning;

/**
 * What this engine reports about itself, and how much of it.
 *
 * These are privacy decisions rather than settings, and they are currently
 * discoverable only by reading the comments in `.env-core`. Tier 2 sends a
 * redacted tail of a failed deploy's log. Source bundles send the customer's
 * application source. Somebody running this engine is entitled to be asked
 * those in words rather than to find them in a file.
 *
 * Two of the four live in different places, and the section says so rather
 * than hiding it: **sending** is a database setting that overrides
 * `TELEMETRY_ENABLED`, and turning it off also syncs that decision to
 * monitoring. The rest are plain env.
 */
class TelemetrySection implements Section
{
    use KeepsAReceipt;

    /** Cleared the first time a settings read throws. */
    private bool $database = true;

    public static function key(): string
    {
        return 'telemetry';
    }

    public static function label(): string
    {
        return 'Telemetry — what this engine reports about itself';
    }

    public static function hint(): string
    {
        return 'Whether reports are sent, and how much they carry.';
    }

    /**
     * Say so when the settings file this writes to is not the one the engine
     * reads. Everything below would otherwise report success and do nothing.
     */
    private function warnAboutEnv(): void
    {
        $why = EnvFile::current()->suspicious();

        if ($why !== null) {
            warning($why);
        }
    }

    public function run(bool $dryRun): int
    {
        while (true) {
            $this->header();

            switch ((string) select(
                label: 'What would you like to change?',
                options: [
                    'sending' => sprintf('%-22s %s', 'Sending', match ($this->sending()) {
                        true => 'on',
                        false => 'off',
                        default => 'unknown',
                    }),
                    'tier' => sprintf('%-22s %s', 'What reports carry', $this->tier(Telemetry::tier())),
                    'bundles' => sprintf('%-22s %s', 'Source code', $this->bundle(Telemetry::sourceBundleMode())),
                    'bugs' => sprintf('%-22s %s', 'Bug reports', $this->bugReports() ? 'allowed' : 'off'),
                    'show' => 'Show exactly what is queued and what is sent with it',
                    'back' => 'Back',
                ],
                default: 'sending',
                scroll: 6,
                hint: 'Nothing here stops deploys being recorded locally. It decides what leaves the server.',
            )) {
                case 'sending':
                    $this->setSending($dryRun);
                    break;

                case 'tier':
                    $this->setTier($dryRun);
                    break;

                case 'bundles':
                    $this->setBundles($dryRun);
                    break;

                case 'bugs':
                    $this->setBugReports($dryRun);
                    break;

                case 'show':
                    $this->show();
                    break;

                default:
                    return 0;
            }
        }
    }

    /** Whether reports are being sent, or null when the setting is unreadable. */
    private function sending(): ?bool
    {
        if (!$this->database) {
            return null;
        }

        try {
            return Telemetry::enabled();
        } catch (Throwable) {
            $this->database = false;

            return null;
        }
    }

    private function header(): void
    {
        $queued = Telemetry::spool()->stats();
        $endpoint = Telemetry::endpoint();

        $sending = $this->sending();

        $lines = [
            sprintf('Sending:     %s', match ($sending) {
                true => 'on',
                false => 'off — reports are kept locally, not sent',
                default => 'unknown — the database could not be read',
            }),
            sprintf('Reports carry: %s', $this->tier(Telemetry::tier())),
            sprintf('Source code: %s', $this->bundle(Telemetry::sourceBundleMode())),
            sprintf('Queued:      %d report(s) waiting', (int) $queued['count']),
            sprintf('Sent to:     %s', $endpoint === '' ? '(nowhere configured — reports are held)' : $endpoint),
        ];

        Screen::draw(self::label(), implode("\n", $lines));

        $this->warnAboutEnv();

        if ($sending === null) {
            // The rest of this section is env and still works; saying which
            // half is unavailable beats one blanket failure.
            warning('The database could not be read, so whether reports are sent cannot be shown or changed. Everything below still can.');
        }

        if ($sending === true && Telemetry::sourceBundleMode() !== SourceBundlePolicy::MODE_OFF) {
            warning('Source bundles are on: a failed deploy uploads the application\'s own source code.');
        }
    }

    /**
     * On and off.
     *
     * Two things, not one: the local switch, and syncing this install's
     * notification preferences to monitoring. `telemetry:enable` and
     * `telemetry:disable` do the same pair; see setSending() for why this
     * does not call them.
     */
    private function setSending(bool $dryRun): void
    {
        $on = $this->sending();

        if ($on === null) {
            warning('The database could not be read, so this cannot be changed here.');
            pause('Press enter to carry on...');

            return;
        }

        Screen::draw(self::label() . '  ·  Sending');
        note(
            $on
                ? "Reports are being sent.\n\nTurning this off keeps every deploy in the local log, where it can still be\n"
                    . 'shared with support by hand. Nothing is deleted and nothing stops working.'
                : "Reports are not being sent.\n\nTurning it on sends anonymous reports about failed and degraded deploys. No\n"
                    . 'username, no repository token, no host address.'
        );

        // Yes by default. Someone who opened this row came to change it, and
        // a No default made Enter a no-op that redrew the same screen — which
        // reads as the wizard being broken rather than as an answer.
        if (!confirm(label: $on ? 'Stop sending reports?' : 'Start sending reports?', default: true)) {
            $this->unchanged();

            return;
        }

        if ($dryRun) {
            note('Dry run: nothing was changed.', 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            // What telemetry:enable and telemetry:disable do, without going
            // through them: running a command from inside this one hands the
            // screen to that command and never gives it back, so everything
            // below would run unseen. Both halves are here because both
            // matter — the local switch, and telling monitoring to stop
            // probing this install.
            Setting::set(NotificationPreferences::SETTING_TELEMETRY_ENABLED, $on ? '0' : '1');
            $sync = NotificationPreferences::sync(!$on);
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        // Read back rather than assumed: the command sets the local switch
        // first and then tells monitoring, and the second half can fail on a
        // box with no outbound network while the first half stands.
        $now = $this->sending();

        if ($now === $on) {
            warning('The setting did not change.');
            pause('Press enter to carry on...');

            return;
        }

        $this->receipt[] = sprintf('Telemetry sending is now %s.', $now ? 'ON' : 'OFF');
        note(end($this->receipt), 'info');

        // The local switch is set before monitoring is told, and the second
        // half can fail on a box with no way out. Say which happened.
        note($sync['ok']
            ? $sync['message']
            : $sync['message'] . ' — the local setting still stands.');

        note(sprintf(
            'Stored as the `%s` setting, which overrides TELEMETRY_ENABLED in .env-core.',
            NotificationPreferences::SETTING_TELEMETRY_ENABLED
        ));
        pause('Press enter to carry on...');
    }

    private function setTier(bool $dryRun): void
    {
        Screen::draw(self::label() . '  ·  What reports carry');

        // Named keys, not the tier numbers. `0, 1, 2` is a PHP list, and the
        // package reads a list's *values* as the options — so the default
        // was searched among the labels, never found, and every visit landed
        // on the first row. The answer was right only by accident, because
        // each label happens to begin with its own digit.
        $tiers = [
            'metadata' => DeployReport::TIER_METADATA,
            'repo' => DeployReport::TIER_REPO,
            'log' => DeployReport::TIER_LOG,
        ];

        $chosen = (string) select(
            label: 'How much should a report carry?',
            options: [
                'metadata' => 'Which app, which stage failed, how long it took. Nothing else.',
                'repo' => 'That, plus which repository was being deployed.',
                'log' => 'That, plus a redacted tail of the deploy log.',
            ],
            default: (string) array_search(Telemetry::tier(), $tiers, true) ?: 'log',
            scroll: 3,
            hint: 'A log tail is redacted, not empty: it is the most useful and the most revealing.',
        );

        $tier = $tiers[$chosen] ?? DeployReport::TIER_LOG;

        $this->writeEnv(
            ['TELEMETRY_TIER' => (string) $tier],
            sprintf('Reports now carry: %s', $this->tier($tier)),
            $dryRun
        );
    }

    private function setBundles(bool $dryRun): void
    {
        Screen::draw(self::label() . '  ·  Source code');
        warning(
            'A source bundle is a zip of the deployed application\'s own code, uploaded with '
            . 'the report. Dependencies, build output and every .env but the examples are left out.'
        );
        note('It is the difference between someone being able to explain a failed deploy and not. It is also your customer\'s code.');

        $mode = (string) select(
            label: 'When should source be uploaded?',
            options: [
                SourceBundlePolicy::MODE_OFF => 'Never',
                SourceBundlePolicy::MODE_UNEXPLAINED => 'Only when a deploy fails for a reason nothing recognises',
                SourceBundlePolicy::MODE_FAILED => 'Whenever a deploy fails',
            ],
            default: Telemetry::sourceBundleMode(),
            scroll: 3,
        );

        // This one keeps its No: it is the only answer here that starts
        // sending somebody else's code.
        if ($mode !== SourceBundlePolicy::MODE_OFF && !confirm(
            label: 'Turn on uploading application source?',
            default: false,
        )) {
            $this->unchanged();

            return;
        }

        $this->writeEnv(
            ['TELEMETRY_SOURCE_BUNDLE' => $mode],
            sprintf('Source code: %s', $this->bundle($mode)),
            $dryRun
        );
    }

    private function setBugReports(bool $dryRun): void
    {
        $on = $this->bugReports();

        Screen::draw(self::label() . '  ·  Bug reports');
        note(
            "`pae telemetry:bug-report <project>` lets somebody here file a report about one\n"
            . "deployed app, with free text they type. This decides whether that is allowed.\n\n"
            . 'Filing is refused outright when sending is off, whatever this says.'
        );

        if (!confirm(label: $on ? 'Stop allowing bug reports?' : 'Allow bug reports?', default: true)) {
            $this->unchanged();

            return;
        }

        $this->writeEnv(
            ['TELEMETRY_BUG_REPORTS' => $on ? 'false' : 'true'],
            sprintf('Bug reports %s.', $on ? 'turned off' : 'allowed'),
            $dryRun
        );
    }

    /** Say that nothing happened, because an unchanged screen cannot. */
    private function unchanged(): void
    {
        note('Left as it is — nothing was changed.', 'warning');
        pause('Press enter to carry on...');
    }

    private function show(): void
    {
        Screen::draw(self::label() . '  ·  What is sent');

        try {
            Artisan::call('telemetry:status');
            $status = trim(Artisan::output());
        } catch (Throwable $e) {
            $status = $e->getMessage();
        }

        // telemetry:status took the screen when it ran; nothing below would be
        // seen without this.
        Screen::restore();
        note($status);

        pause('Press enter to carry on...');
    }

    /**
     * @param array<string, string> $values
     */
    private function writeEnv(array $values, string $said, bool $dryRun): void
    {
        if ($dryRun) {
            note('Dry run: ' . $said, 'warning');
            pause('Press enter to carry on...');

            return;
        }

        try {
            EnvFile::current()->set($values);
        } catch (Throwable $e) {
            error($e->getMessage());
            pause('Press enter to carry on...');

            return;
        }

        // The process booted with the old values and the menu reads them back
        // from config on the next turn of the loop.
        foreach ($values as $key => $value) {
            config([$this->configKey($key) => $this->cast($key, $value)]);
        }

        $this->receipt[] = $said;
        note($said, 'info');
        pause('Press enter to carry on...');
    }

    private function configKey(string $env): string
    {
        return match ($env) {
            'TELEMETRY_TIER' => 'telemetry.tier',
            'TELEMETRY_SOURCE_BUNDLE' => 'telemetry.source_bundle.mode',
            default => 'telemetry.bug_reports.enabled',
        };
    }

    private function cast(string $env, string $value): mixed
    {
        return match ($env) {
            'TELEMETRY_TIER' => (int) $value,
            'TELEMETRY_BUG_REPORTS' => $value === 'true',
            default => $value,
        };
    }

    private function bugReports(): bool
    {
        return (bool) config('telemetry.bug_reports.enabled', true);
    }

    private function tier(int $tier): string
    {
        return match ($tier) {
            DeployReport::TIER_METADATA => 'what failed and when — nothing more',
            DeployReport::TIER_REPO => 'that, plus which repository',
            default => 'that, plus a redacted tail of the deploy log',
        };
    }

    private function bundle(string $mode): string
    {
        return match ($mode) {
            SourceBundlePolicy::MODE_UNEXPLAINED => 'uploaded when a deploy fails unexplained',
            SourceBundlePolicy::MODE_FAILED => 'uploaded whenever a deploy fails',
            default => 'never uploaded',
        };
    }
}
