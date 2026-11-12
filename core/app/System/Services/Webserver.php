<?php

namespace App\System\Services;

use App\Models\Domain;
use App\System as EngineSystem;
use App\System\Privileged;
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
    ) {}

    public function isRunning(): bool
    {
        return $this->system->isComposeServiceRunning('sites-http');
    }

    /** Whether an nginx-proxy vhost file exists for the domain, whichever webserver driver is selected. */
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

        $webserver = $this->webserverFromComposeOverride() ?? $this->webserverFromContainerLabels();
        self::$currentWebserver = $webserver;

        return $webserver;
    }

    /** The label in docker-compose.yml-webserver, lowercased, or null when there is none. */
    private function webserverFromComposeOverride(): ?string
    {
        $composeWebserverFile = $this->system->engineDirPath().'/docker-compose.yml-webserver';
        if (file_exists($composeWebserverFile)) {
            $contents = @file_get_contents($composeWebserverFile);
            if (is_string($contents)) {
                if (preg_match('/com\.panelalpha\.webserver\s*=\s*([a-z0-9_\-]+)/i', $contents, $m)) {
                    return strtolower($m[1]);
                }
            }
        }

        return null;
    }

    private function webserverFromContainerLabels(): string
    {
        $response = $this->system->exec(Privileged::compose($this->system->composeFilePath())->serviceContainers('sites-http'));

        /** @var ?object{Labels: string} $result */
        $result = json_decode($response);
        if ($result === null) {
            throw new \Exception('Could not parse docker compose response');
        }

        $labels = explode(',', $result->Labels);

        return self::webserverLabel($labels);
    }

    /** @param  array<string>  $labels */
    private static function webserverLabel(array $labels): string
    {
        $webserver = 'unknown';
        foreach ($labels as $label) {
            if (Str::startsWith($label, 'com.panelalpha.webserver=')) {
                $webserver = Str::after($label, 'com.panelalpha.webserver=');
                break;
            }
        }

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
     * @param  ?array<Domain>  $domains
     */
    public function rebuildConfig(?array $domains = null): void
    {
        $this->driver()->rebuildConfig($domains);
    }

    public function reload(bool $rebindIpListeners = false): void
    {
        $this->driver()->reload($rebindIpListeners);
    }

    public function reloadRouting(): void
    {
        $this->driver()->reloadRouting();
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
     * @param  array<string>  $domainNames
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
     * @param  array<string>  $domainNames
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
     * `started_at` is the mtime of `pid`, the one file a run writes once, at its start; the `latest` symlink's
     * own time moves whenever it is re-pointed.
     *
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
        $latestLink = '/opt/panelalpha/log/change-webserver/latest';
        $fs = $this->system->filesystem();
        if (! $fs->directoryExists($latestLink)) {
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
            'started_at' => $fs->mtime($pidFile),
            'finished_at' => $fs->mtime($exitCodeFile),
            'pid' => (is_numeric($pid) ? (int) $pid : null),
            'exit_code' => (is_numeric($exitCode) ? (int) $exitCode : null),
            'tail_stdout' => $tailStdout,
            'tail_stderr' => $tailStderr,
            'from_version' => $fs->cat($fromVersionFile),
            'to_version' => $fs->cat($toVersionFile),
            'logs_path' => $latestLink,
        ];
    }

    public function runChangeWebserverScript(string $newWebserver, ?string $serialNumber = null): void
    {
        $process = $this->system->runProcess(
            Privileged::host()->changeWebserver($this->system->engineDirPath(), $newWebserver, $serialNumber)
        );

        if ($process->getExitCode() !== 0) {
            $message = 'Failed to run change webserver script: ';
            $message .= ($process->getErrorOutput() ?: $process->getOutput());
            $message .= ' (exit code '.(string) $process->getExitCode().')';
            throw new \Exception($message);
        }
    }

    public function isChangeWebserverScriptRunning(): bool
    {
        $latestLink = '/opt/panelalpha/log/change-webserver/latest';
        if (! $this->system->filesystem()->directoryExists($latestLink)) {
            return false;
        }

        $pidFile = "$latestLink/pid";
        if (! file_exists($pidFile)) {
            return false;
        }

        $pid = trim(file_get_contents($pidFile));

        if (! ctype_digit($pid) || (int) $pid < 1) {
            return false;
        }

        $process = $this->system->runProcess(Privileged::host()->processExists((int) $pid));

        return $process->getExitCode() === 0;
    }

    /**
     * Follows the certificates proxy rules on 443 name, which certbot or an administrator change outside the
     * engine. True when nginx was reloaded.
     */
    public function refreshProxyRuleCertificates(): bool
    {
        $driver = $this->driver();
        if (! $driver instanceof NginxProxy || ! $driver->refreshProxyRuleCertificates()) {
            return false;
        }
        $driver->reloadRouting();

        return true;
    }

    /** The default servers and firewall allows of the ports domains answer HTTPS on besides 443; only nginx-proxy has any. */
    public function applyHttpsPorts(): void
    {
        $driver = $this->driver();
        if ($driver instanceof NginxProxy) {
            $driver->applyHttpsPorts();
        }
    }

    public function rebuildDomains(): void
    {
        $driver = $this->driver();
        $driver->rebuildConfig();
        $driver->reload();
    }

    /**
     * Restarts sites-http from the host (an in-container restart exits PID 1), refused while `nginx -t` fails, which
     * would crash-loop it; what nginx said names every account's paths, so only the log gets it.
     */
    public function restartWebserverContainerFromHost(): void
    {
        if ($this->webserverConfigProblem() !== null) {
            throw new \Exception(
                'Refusing to restart sites-http: the rendered configuration does not pass `nginx -t`. '
                .'The running container is left as it is, so sites keep serving. '
                .'What nginx said is in the engine log.'
            );
        }

        $this->system->runProcessOnHost(
            Privileged::compose($this->system->composeFilePath())->restart('sites-http')
        );
    }

    /** How long the config test may take before it counts as "could not ask". */
    private const CONFIG_TEST_TIMEOUT = 20;

    /**
     * `nginx -t`'s verdict when it fails, run inside sites-http so vhost paths resolve as the webserver's do;
     * null when the config passes, the webserver is not nginx, or the test could not run.
     */
    private function webserverConfigProblem(): ?string
    {
        try {
            $webserver = $this->detectWebserver();
        } catch (\Throwable $e) {
            return null;
        }
        if (! in_array($webserver, ['nginx', 'nginx-proxy'], true)) {
            return null;
        }

        try {
            $process = $this->system->runProcessOnHost(
                Privileged::engineServices($this->system->composeFilePath())->nginxConfigTest(),
                [],
                self::CONFIG_TEST_TIMEOUT
            );
            $output = trim($process->getErrorOutput()."\n".$process->getOutput());
            $problem = self::configProblemFrom($process->getExitCode(), $output);
        } catch (\Throwable $e) {
            Log::warning('Webserver config test could not be run: '.$e->getMessage());

            return null;
        }

        if ($problem !== null) {
            Log::warning('Webserver config test failed', ['webserver' => $webserver, 'output' => $output]);
        }

        return $problem;
    }

    /**
     * The decision over an `nginx -t` exit code and its output: only nginx's own verdict refuses, never a paused
     * container, a failed exec, a broker that refused, or no output. Testable without a host.
     */
    public static function configProblemFrom(?int $exitCode, string $output): ?string
    {
        if ($exitCode === 0) {
            return null;
        }

        if (! self::isNginxTestVerdict($output)) {
            return null;
        }

        return self::nginxVerdictLine($output);
    }

    private static function isNginxTestVerdict(string $output): bool
    {
        return Str::contains($output, ['[emerg]', 'test failed']);
    }

    /** The line nginx refused on, bounded, so compose warnings cannot push it out. */
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
     * Asks the broker to reload once this request is answered, detecting the webserver afresh, since a
     * `change-webserver` earlier in this process may have replaced it. A failed detection queues nothing.
     *
     * @param  bool  $rebindIpListeners  nginx only: a delayed host-level container restart, never an init.d one inside
     */
    public function scheduleWebserverReloadInBackground(bool $rebindIpListeners = false): void
    {
        $composeFile = $this->system->composeFilePath();
        $webserver = null;
        try {
            $webserver = $this->detectWebserver();
        } catch (\Exception $e) {
            return;
        }

        $queue = Privileged::webserver()->queueReload($composeFile, $webserver, $rebindIpListeners);
        if ($queue === null) {
            return;
        }

        $this->queueOnHost($queue);
    }

    /**
     * Nothing else would say so: a reload the broker could not be asked to run never happens.
     *
     * @param  list<string>  $queue
     */
    private function queueOnHost(array $queue): void
    {
        $process = $this->system->runProcessOnHost($queue);
        if (! $process->isSuccessful()) {
            Log::warning('Could not queue the webserver reload on the host: '
                .Str::limit(trim($process->getErrorOutput().' '.$process->getOutput()), 200));
        }
    }
}
