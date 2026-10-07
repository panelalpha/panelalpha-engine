<?php

namespace App\System\Services;

use App\Models\Domain;
use App\System as EngineSystem;
use App\System\Services\Webserver\Apache;
use App\System\Services\Webserver\Litespeed;
use App\System\Services\Webserver\Nginx;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\Openlitespeed;
use App\System\Services\Webserver\WebserverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Host webserver container (sites-http) and webserver.sh lifecycle.
 */
class Webserver implements WebserverInterface
{
    protected static ?string $currentWebserver = null;

    public function __construct(
        private EngineSystem $system,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->system->isComposeServiceRunning('sites-http');
    }

    /**
     * Whether an nginx-proxy vhost file exists for the domain.
     * Always the nginx-proxy tree — not the currently selected webserver driver.
     */
    public function domainExists(string $domainName): bool
    {
        return (new NginxProxy($this->system))->domainConfigExists($domainName);
    }

    /**
     * The webserver slug without the static memo, for callers that must see a
     * `change-webserver` that happened inside one process.
     */
    public function detectWebserver(): string
    {
        self::$currentWebserver = null;

        return $this->getCurrentWebserver();
    }

    public function getCurrentWebserver(): string
    {
        if (self::$currentWebserver !== null) {
            return self::$currentWebserver;
        }

        $composeWebserverFile = $this->system->engineDirPath() . '/docker-compose.yml-webserver';
        if (file_exists($composeWebserverFile)) {
            $contents = @file_get_contents($composeWebserverFile);
            if (is_string($contents)) {
                if (preg_match('/com\.panelalpha\.webserver\s*=\s*([a-z0-9_\-]+)/i', $contents, $m)) {
                    $webserver = strtolower($m[1]);
                    self::$currentWebserver = $webserver;
                    return $webserver;
                }
                if (preg_match('/-\s*com\.panelalpha\.webserver\s*=\s*([a-z0-9_\-]+)/i', $contents, $m2)) {
                    $webserver = strtolower($m2[1]);
                    self::$currentWebserver = $webserver;
                    return $webserver;
                }
            }
        }

        $response = $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'ps',
            '-a',
            'sites-http',
            '--format',
            'json',
        ]);

        /** @var ?object{Labels: string} $result */
        $result = json_decode($response);
        if ($result === null) {
            throw new \Exception('Could not parse docker compose response');
        }

        $webserver = "unknown";
        $labels = explode(',', $result->Labels);
        foreach ($labels as $label) {
            if (Str::startsWith($label, 'com.panelalpha.webserver=')) {
                $webserver = Str::after($label, 'com.panelalpha.webserver=');
                break;
            }
        }

        self::$currentWebserver = $webserver;
        return $webserver;
    }

    public function driver(?string $slug = null): WebserverInterface
    {
        $slug ??= $this->getCurrentWebserver();

        return match ($slug) {
            'litespeed' => new Litespeed($this->system),
            'openlitespeed' => new Openlitespeed($this->system),
            'apache' => new Apache($this->system),
            'nginx' => new Nginx($this->system),
            'nginx-proxy' => new NginxProxy($this->system),
            default => throw new \Exception("Invalid webserver: {$slug}"),
        };
    }

    public function getDetails(): array
    {
        return $this->driver()->getDetails();
    }

    public function resetWebPanelPassword(): string
    {
        return $this->driver()->resetWebPanelPassword();
    }

    public function updateConfig(string $name, string $value): void
    {
        $this->driver()->updateConfig($name, $value);
    }

    public function rebuildDomainConfig(Domain $domain): void
    {
        $this->driver()->rebuildDomainConfig($domain);
    }

    /**
     * @param ?array<Domain> $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $this->driver()->rebuildConfig($domains);
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        $this->driver()->reload($rebindIpListeners);
    }

    public function needsIpListenerRebind(): bool
    {
        return $this->driver()->needsIpListenerRebind();
    }

    public function restart(): void
    {
        $this->driver()->restart();
    }

    public function addDomain(Domain $domain): void
    {
        $this->driver()->addDomain($domain);
    }

    /**
     * @return array<string>
     */
    public function listDomains(): array
    {
        return $this->driver()->listDomains();
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $this->driver()->deleteDomainConfig($domainName);
    }

    /**
     * @param array<string> $domainNames
     */
    public function deleteDomainsConfigs(array $domainNames): void
    {
        $this->driver()->deleteDomainsConfigs($domainNames);
    }

    public function deleteDomainLogsDir(string $domainName): void
    {
        $this->driver()->deleteDomainLogsDir($domainName);
    }

    /**
     * @param array<string> $domainNames
     */
    public function deleteDomainsLogsDirs(array $domainNames): void
    {
        $this->driver()->deleteDomainsLogsDirs($domainNames);
    }

    public function toggleModsecurity(): void
    {
        $this->driver()->toggleModsecurity();
    }

    public function toggleLscache(): void
    {
        $this->driver()->toggleLscache();
    }

    /**
     * @return ?array{
     *   started_at: ?int,
     *   finished_at: ?int,
     *   pid: ?int,
     *   exit_code: ?int,
     *   tail_stdout: ?string,
     *   tail_stderr: ?string,
     *   from_version: ?string,
     *   to_version: ?string,
     *   logs_path: string
     * }
     */
    public function getLatestChangeWebserverInfo(): ?array
    {
        $latestLink = "/opt/panelalpha/log/change-webserver/latest";
        $fs = $this->system->filesystem();
        if (!$fs->directoryExists($latestLink)) {
            return null;
        }

        $pidFile = "$latestLink/pid";
        $exitCodeFile = "$latestLink/exit_code";
        $stdoutFile = "$latestLink/stdout";
        $stderrFile = "$latestLink/stderr";
        $fromVersionFile = "$latestLink/from_version";
        $toVersionFile = "$latestLink/to_version";

        $pid = $fs->cat($pidFile);
        $exitCode = $fs->cat($exitCodeFile);

        $tailStdout = $fs->tail($stdoutFile, 2);
        if ($tailStdout) {
            $tailStdout = preg_replace('/\x1B\[[0-9;]*[mK]/', '', $tailStdout);
        }
        $tailStderr = $fs->tail($stderrFile, 2);
        if ($tailStderr) {
            $tailStderr = preg_replace('/\x1B\[[0-9;]*[mK]/', '', $tailStderr);
        }

        return [
            'started_at' => $fs->mtime($latestLink),
            'finished_at' => $fs->mtime($exitCodeFile),
            'pid' => (is_numeric($pid) ? (int)$pid : null),
            'exit_code' => (is_numeric($exitCode) ? (int)$exitCode : null),
            'tail_stdout' => $tailStdout,
            'tail_stderr' => $tailStderr,
            'from_version' => $fs->cat($fromVersionFile),
            'to_version' => $fs->cat($toVersionFile),
            'logs_path' => $latestLink,
        ];
    }

    public function runChangeWebserverScript(string $newWebserver, ?string $serialNumber = null): void
    {
        $command = [
            'sudo',
            'nsenter',
            '--target',
            '1',
            '--all',
            'bash',
            $this->system->engineDirPath() . '/webserver.sh',
            '--set',
            $newWebserver,
        ];

        if ($serialNumber !== null && $serialNumber !== '') {
            $command[] = '--serial-no=' . $serialNumber;
        }

        $command[] = '--background';

        $process = $this->system->runProcess($command);

        if ($process->getExitCode() !== 0) {
            $message = "Failed to run change webserver script: ";
            $message .= ($process->getErrorOutput() ?: $process->getOutput());
            $message .= " (exit code " . (string)$process->getExitCode() . ")";
            throw new \Exception($message);
        }
    }

    public function isChangeWebserverScriptRunning(): bool
    {
        $latestLink = "/opt/panelalpha/log/change-webserver/latest";
        if (!$this->system->filesystem()->directoryExists($latestLink)) {
            return false;
        }

        $pidFile = "$latestLink/pid";
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = trim(file_get_contents($pidFile));

        if (!ctype_digit($pid)) {
            return false;
        }

        $process = $this->system->runProcess([
            'sudo',
            'nsenter',
            '--target',
            '1',
            '--all',
            'kill',
            '-0',
            $pid,
        ]);

        return $process->getExitCode() === 0;
    }

    public function rebuildDomains(): void
    {
        $driver = $this->driver();
        $driver->rebuildConfig();
        $driver->reload();
    }

    /**
     * Restart the webserver container from the host namespace.
     * Prefer this over `/etc/init.d/nginx restart` inside the container —
     * that exits PID 1 and loops the service (see Modsec::restartWebserver).
     *
     * A restart is the one path here with no fallback: handed an unusable
     * config the container exits on the `[emerg]` and loops under
     * `restart: always`, taking every site on the host down with it.
     * So the config is tested first, and the restart is refused while
     * the test fails. Only nginx's own verdict refuses -- a container that is
     * down is exactly what a restart is for.
     */
    public function restartWebserverContainerFromHost(): void
    {
        // What nginx said names vhost and certificate paths of every account on
        // the host, so it goes to the log; the caller gets the verdict alone.
        if ($this->webserverConfigProblem() !== null) {
            throw new \Exception(
                'Refusing to restart sites-http: the rendered configuration does not pass `nginx -t`. '
                . 'The running container is left as it is, so sites keep serving. '
                . 'What nginx said is in the engine log.'
            );
        }

        $composeFile = $this->system->composeFilePath();
        $this->system->runProcessOnHost(
            'sudo docker compose -f ' . escapeshellarg($composeFile) . ' restart sites-http'
        );
    }

    /** How long the config test may take before it counts as "could not ask". */
    private const CONFIG_TEST_TIMEOUT = 20;

    /**
     * The nginx config test's verdict when it fails, or null when the config is
     * fine -- or when there was nothing to ask. The test runs *inside*
     * sites-http so the paths in the vhosts resolve the way the webserver
     * resolves them; running it on the host would read a different filesystem.
     *
     * A config that cannot be tested is never a reason to refuse.
     */
    private function webserverConfigProblem(): ?string
    {
        // `nginx -t` is nginx's own test and says nothing about the others, so
        // Apache and LiteSpeed are not tested here, and are not refused.
        try {
            $webserver = $this->detectWebserver();
        } catch (\Throwable $e) {
            return null;
        }
        if (!in_array($webserver, ['nginx', 'nginx-proxy'], true)) {
            return null;
        }

        $composeFile = $this->system->composeFilePath();
        try {
            $process = $this->system->runProcessOnHost(
                'sudo docker compose -f ' . escapeshellarg($composeFile) . ' exec -T sites-http nginx -t',
                [],
                self::CONFIG_TEST_TIMEOUT
            );
            $output = trim($process->getErrorOutput() . "\n" . $process->getOutput());
            $problem = self::configProblemFrom($process->getExitCode(), $output);
        } catch (\Throwable $e) {
            // A test that timed out or never started is not a verdict about the config.
            Log::warning('Webserver config test could not be run: ' . $e->getMessage());

            return null;
        }

        if ($problem !== null) {
            Log::warning('Webserver config test failed', ['webserver' => $webserver, 'output' => $output]);
        }

        return $problem;
    }

    /**
     * The decision itself, over an `nginx -t` exit code and its output.
     *
     * Separated from running it so it can be tested without a host, a compose
     * file or a container.
     */
    public static function configProblemFrom(?int $exitCode, string $output): ?string
    {
        if ($exitCode === 0) {
            return null;
        }

        // Only nginx's own verdict refuses. Everything else that makes the
        // command fail -- a paused or stopping container, an exec that never
        // reached nginx, a sudo or nsenter error, no output at all -- says
        // nothing about the configuration, and a restart is what fixes most of
        // it.
        if (!self::isNginxTestVerdict($output)) {
            return null;
        }

        return self::nginxVerdictLine($output);
    }

    private static function isNginxTestVerdict(string $output): bool
    {
        return Str::contains($output, ['[emerg]', 'test failed']);
    }

    /** The line nginx refused on, bounded -- compose warnings must not push it out. */
    private static function nginxVerdictLine(string $output): string
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (self::isNginxTestVerdict($line)) {
                return Str::limit(trim($line), 200);
            }
        }

        return Str::limit(trim($output), 200);
    }

    /**
     * A restart with `nginx -t` in front of it, as one shell command: a queued
     * restart has to test the config next to the restart, not when the job was
     * scheduled. Only nginx's own verdict stops it -- a test that could not run
     * does not, since a container that is down is what a restart is for.
     */
    public static function restartGuardedByConfigTest(string $testCmd, string $restartCmd): string
    {
        return 'out=$(' . $testCmd . ' 2>&1); '
            . 'case "$out" in *"[emerg]"*|*"test failed"*) '
            . 'echo "sites-http restart refused, nginx -t: $out" >&2; exit 1;; esac; '
            . $restartCmd;
    }

    /**
     * Schedule a webserver reload on the host in the background (non-blocking).
     * Uses `at now` to enqueue the command inside the host namespace.
     *
     * @param bool $rebindIpListeners When true (nginx only), schedule a host-level
     *        container restart after a short delay so the current HTTP response can
     *        leave through the proxy first. Never uses in-container init.d restart.
     */
    public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
    {
        $composeFile = $this->system->composeFilePath();
        $webserver = null;
        try {
            // Freshly read: a `change-webserver` earlier in this process would
            // otherwise have this queue a reload for the server that is gone.
            $webserver = $this->detectWebserver();
        } catch (\Exception $e) {
            // if detection fails, do nothing
            return;
        }

        $composeFileArg = escapeshellarg($composeFile);

        switch ($webserver) {
            case 'litespeed':
            case 'openlitespeed':
                $inner = "sudo docker compose -f {$composeFileArg} exec -T sites-http /usr/local/lsws/bin/lswsctrl condrestart";
                break;
            case 'nginx':
            case 'nginx-proxy':
                if ($rebindIpListeners) {
                    // Delay so the in-flight API response can finish through the proxy.
                    // The restart is the one path that turns an unusable config into a
                    // crash loop, so the queued job tests it right before restarting.
                    $inner = 'sleep 5; ' . self::restartGuardedByConfigTest(
                        "sudo docker compose -f {$composeFileArg} exec -T sites-http nginx -t",
                        "sudo docker compose -f {$composeFileArg} restart sites-http"
                    );
                } else {
                    $inner = "sudo docker compose -f {$composeFileArg} exec -T sites-http /etc/init.d/nginx reload";
                }
                break;
            case 'apache':
                $inner = "sudo docker compose -f {$composeFileArg} exec -T sites-http apachectl -k graceful";
                break;
            default:
                // unsupported or no-op
                return;
        }

        $pipeline = 'echo ' . escapeshellarg($inner) . ' | at now';
        $hostCmd = 'bash -lc ' . escapeshellarg($pipeline);
        $process = $this->system->runProcessOnHost($hostCmd);
        // Nothing else would say so: a host without `at` never runs the job.
        if (!$process->isSuccessful()) {
            Log::warning('Could not queue the webserver reload on the host: '
                . Str::limit(trim($process->getErrorOutput() . ' ' . $process->getOutput()), 200));
        }
    }
}
