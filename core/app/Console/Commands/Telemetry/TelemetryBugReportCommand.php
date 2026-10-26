<?php

namespace App\Console\Commands\Telemetry;

use App\Lib\Deploy\Telemetry\BugReport;
use App\Lib\Deploy\Telemetry\Telemetry;
use Illuminate\Console\Command;

/**
 * File a bug about a deployed app, from the box it is running on.
 *
 * The console half of `POST /bug-reports`, and the surface that matters most:
 * the person who notices an app is wrong is usually the one SSHed into the
 * machine at the time, and asking them to go and find an API token first is how
 * a report turns into no report.
 *
 * The project is required, and everything expensive about the report is
 * gathered from it here: what the engine detects the application to be, a live
 * probe of every port it publishes, its last deploy, and the tail of that
 * deploy's log. What is left for a human is the two things only they know —
 * what went wrong, and what should have happened instead.
 *
 * `--dry-run` prints the report and sends nothing, for the same reason
 * `telemetry:show` exists: nobody should have to take the engine's word for
 * what it is about to say about their customers.
 */
class TelemetryBugReportCommand extends Command
{
    /** The obvious spelling of it, for anyone who does not think in namespaces. */
    protected $aliases = ['bug:report'];

    protected $signature = 'telemetry:bug-report
                            {project? : The project the report is about (prompted for when omitted)}
                            {--title= : One-line headline (prompted for when omitted)}
                            {--description= : What happened; "-" reads it from stdin}
                            {--severity= : low, normal, high or critical (default: normal)}
                            {--area= : Which part of the engine, e.g. deploy, ssl, mysql}
                            {--no-log : Do not attach the deploy log tail}
                            {--no-health : Do not probe the application while filing}
                            {--contact= : Address support can answer on. Sent unredacted}
                            {--dry-run : Print the report that would be filed and file nothing}
                            {--json : Print the result as JSON}';

    protected $description = 'Report a bug about a deployed app to PanelAlpha over the telemetry channel';

    public function handle(): int
    {
        // Positional, because it is the subject of the report rather than a
        // modifier on it: `telemetry:bug-report shop` reads the way it means.
        $project = trim((string) $this->argument('project'));
        if ($project === '' && $this->input->isInteractive()) {
            $project = trim((string) $this->ask('Which project is this about'));
        }

        $title = $this->text('title', 'What is wrong, in one line');
        $description = $this->description();

        if ($project === '') {
            $this->error('A bug report is about one application: name the project it concerns.');

            return self::FAILURE;
        }

        if ($title === '' || $description === '') {
            $this->error('A bug report needs both a title and a description.');

            return self::FAILURE;
        }

        // The probe reaches into the account container and can take a few
        // seconds. Say so before it starts, or a person who typed one sentence
        // is left watching a cursor.
        if (! $this->option('no-health') && ! $this->option('json')) {
            $this->line('<comment>Gathering evidence from '.$project
                .' — inspection and a live port probe…</comment>');
        }

        $result = Telemetry::captureBugReport([
            'project' => $project,
            'title' => $title,
            'description' => $description,
            'severity' => $this->text('severity', ''),
            'area' => $this->text('area', ''),
            'contact' => $this->text('contact', ''),
            'attach_log' => ! $this->option('no-log'),
            'attach_health' => ! $this->option('no-health'),
            'dry_run' => (bool) $this->option('dry-run'),
            'via' => 'cli',
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['queued'] || $result['status'] === 'preview' ? self::SUCCESS : self::FAILURE;
        }

        return $this->report($result);
    }

    /**
     * @param  array{status: string, queued: bool, id: ?string, reason: ?string, endpoint: string, report: ?array<string, mixed>}  $result
     */
    private function report(array $result): int
    {
        if ($result['status'] === 'preview') {
            $this->line((string) json_encode($result['report'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->line('<comment>Nothing was filed. This is what would be sent to '
                .$result['endpoint'].' — run again without --dry-run to file it.</comment>');

            return self::SUCCESS;
        }

        if (! $result['queued']) {
            $this->error((string) $result['reason']);

            // Every refusal is a configuration answer, and the one people hit
            // is telemetry being off. Say where the switch is rather than
            // leaving them to find it.
            if ($result['status'] === 'disabled') {
                $this->newLine();
                $this->line('<comment>Set TELEMETRY_ENABLED=true (and TELEMETRY_BUG_REPORTS=true) in '
                    .'.env-core, or send storage/logs/telemetry-*.log to support instead.</comment>');
            }

            return self::FAILURE;
        }

        $report = $result['report'];
        $bug = $report['bug'] ?? [];

        $this->info('Bug report queued.');
        $this->table(['Field', 'Value'], [
            ['ID', (string) $result['id']],
            ['Severity', (string) ($bug['severity'] ?? BugReport::DEFAULT_SEVERITY)],
            ['Area', (string) ($bug['area'] ?? BugReport::DEFAULT_AREA)],
            ['Title', (string) ($bug['title'] ?? '')],
            ['Contact', (string) ($bug['contact'] ?? '(none)')],
            // What the engine gathered, so the reporter can see the report is
            // more than the sentence they typed -- and can see what is missing
            // when a section could not be collected.
            ['App inspection', isset($report['app']['inspect']) ? 'attached' : 'not available'],
            ['Health probe', $this->healthSummary($report)],
            ['Last deploy', isset($report['deploy']) ? 'attached' : 'none recorded'],
            ['Log lines attached', (string) count($report['log_tail'] ?? [])],
            ['Endpoint', $result['endpoint']],
        ]);

        $this->newLine();
        $this->line('<comment>Queued, not sent: telemetry:ship delivers it on the next scheduled run. '
            .'Read it back with `telemetry:show '.$result['id'].'`.</comment>');

        return self::SUCCESS;
    }

    /**
     * What the probe found, in the words the health report itself uses.
     *
     * `healthy` and `serving` answer different questions and the summary keeps
     * both: something can answer on every port and still be serving the
     * engine's own placeholder page, which is exactly the bug most worth
     * filing.
     *
     * @param  array<string, mixed>  $report
     */
    private function healthSummary(array $report): string
    {
        $health = $report['app']['health'] ?? null;
        if (! is_array($health)) {
            return $this->option('no-health') ? 'skipped (--no-health)' : 'not available';
        }

        $healthy = match ($health['healthy'] ?? null) {
            true => 'answering',
            false => 'not answering',
            default => 'nothing to probe',
        };
        $serving = is_string($health['serving'] ?? null) ? $health['serving'] : null;

        return $serving === null || $serving === 'ok'
            ? $healthy
            : $healthy.', serving '.$serving;
    }

    /**
     * An option, or a prompt for it when a person is watching.
     *
     * Only the two required fields are ever prompted for; the rest default,
     * because a command that interrogates an operator about severity and area
     * before letting them say what broke is a command they stop using.
     */
    private function text(string $option, string $prompt): string
    {
        $value = trim((string) $this->option($option));

        if ($value === '' && $prompt !== '' && $this->input->isInteractive()) {
            $value = trim((string) $this->ask($prompt));
        }

        return $value;
    }

    /**
     * The body: an option, standard input, or an editor-free prompt.
     *
     * `--description=-` reads stdin to the end, which is what makes this
     * scriptable and what lets somebody pipe in the paragraph they already
     * wrote somewhere else.
     */
    private function description(): string
    {
        $value = (string) $this->option('description');

        if ($value === '-') {
            $stdin = stream_get_contents(STDIN);

            return trim(is_string($stdin) ? $stdin : '');
        }

        $value = trim($value);
        if ($value !== '' || ! $this->input->isInteractive()) {
            return $value;
        }

        $this->line('What happened, what you expected, and how to reproduce it.');
        $this->line('<comment>Finish with an empty line.</comment>');

        $lines = [];
        while (true) {
            $line = $this->ask(' ', '');
            if (trim((string) $line) === '') {
                break;
            }
            $lines[] = $line;
        }

        return trim(implode("\n", $lines));
    }
}
