<?php

namespace App\System\Project;

use App\Integrations\Statistics\Statistics;
use App\Lib\HttpAcmeChallengeStore;
use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\Lib\Ssl\IssuableDomain;
use App\Lib\Ssl\Issuers;
use App\Models\Domain as DomainModel;
use App\Models\Setting;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as UserProject;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\WebserverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Domain implements IssuableDomain
{
    public function __construct(
        private readonly UserProject $project,
        private readonly DomainModel $model,
    ) {
    }

    public function model(): DomainModel
    {
        return $this->model;
    }

    public function project(): UserProject
    {
        return $this->project;
    }

    public function create(): void
    {
        try {
            if ($this->model->sslEnabled()) {
                $this->generateCertificate();
            }

            $this->createDomainRootDir();

            $this->project->createDomainConfig($this->model);
            $this->project->reloadWebserver();

            $this->hostWebserver()->addDomain($this->model);
            $this->hostWebserver()->reload(false);
            $this->syncStatisticsConfig();
        } catch (\Exception $e) {
            // Cleanup is best-effort; its own failure must not replace the error that caused it.
            try {
                $this->delete();
            } catch (\Throwable $cleanup) {
                \Illuminate\Support\Facades\Log::warning(
                    "Cleanup after failed domain create failed for {$this->model->domain}: " . $cleanup->getMessage()
                );
            }
            throw $e;
        }
    }

    public function rebuild(bool $reload = true): void
    {
        if ($this->model->sslEnabled() && !$this->hasSslCertificate()) {
            $this->generateCertificate();
        }
        $this->project->rebuildDomain($this->model);
        $this->hostWebserver()->rebuildDomainConfig($this->model);
    }

    public function delete(): void
    {
        try {
            \App\Integrations\Tunnels\TunnelManager::teardownDomain($this->userModel(), $this->model);
        } catch (\Throwable $e) {
            Log::warning(
                "Cloudflare teardown during domain delete failed for {$this->model->domain}: " . $e->getMessage()
            );
        }

        try {
            \App\Models\ProxyRule::query()
                ->where('server_name', $this->model->domain)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning(
                "ProxyRule cleanup during domain delete failed for {$this->model->domain}: " . $e->getMessage()
            );
        }

        $this->forgetStatistics();

        $this->hostWebserver()->deleteDomainConfig($this->model->domain);
        if (!config('env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS')) {
            $this->hostWebserver()->deleteDomainLogsDir($this->model->domain);
        }
        $this->hostWebserver()->reload();

        $this->project->deleteDomainConfig($this->model->domain);
        $this->project->reloadWebserver();

        (new HttpAcmeChallengeStore())->deleteDomainDir($this->model->domain);
    }

    public function createDomainRootDir(): void
    {
        $domainName = $this->model->domain;
        $user = $this->userModel();
        $uid = $user->getUid();
        $gid = $user->getGid();
        $owner = "{$uid}:{$gid}";
        $templateVars = [
            'user' => $user->username,
            'domain' => $domainName,
        ];

        $system = $this->system();
        $fs = $system->filesystem();
        $home = $system->projectHomeDirPath($this->project->username());
        $domainDir = $home . '/' . $domainName;
        $domainRootDir = $home . $this->model->getDocumentRoot();

        $system->exec("sudo mkdir -p {$domainDir}");
        $system->exec("sudo chown {$owner} {$domainDir}");

        $publicHtmlMissing = !$fs->isDir($domainRootDir);
        $system->exec("sudo mkdir -p {$domainRootDir}");
        $system->exec("sudo chown {$owner} {$domainRootDir}");

        if ($publicHtmlMissing) {
            $template = $user->getTemplate() ?? 'default';
            $domainTemplateDir = $system->projectDomainTemplateDirPath($template) . '/public_html';
            if (!$fs->isDir($domainTemplateDir)) {
                $domainTemplateDir = $system->templatesDirPath() . '/user-home/default-site';
            }
            $fs->makeDirFromTemplate($domainRootDir, $domainTemplateDir, $templateVars, $owner);
            $system->exec("sudo chmod -R ugo-rwx,u+rwX,go+rX " . $domainRootDir);
        }
    }

    public function generateCertificate(): void
    {
        if ($this->model->domain == Setting::get('vhost-default-ip-domain')) {
            $system = $this->system();
            $engineDir = $system->engineDirPath();
            $serverCertFile = $engineDir . '/crt/server.cert';
            $serverKeyFile = $engineDir . '/crt/server.key';

            $certDir = "{$this->projectDirPath()}/ssl-certs";
            $certFile = "{$certDir}/{$this->model->domain}.crt";
            $caFile = "{$certDir}/{$this->model->domain}.ca";
            $pemFile = "{$certDir}/{$this->model->domain}.pem";
            $keyFile = "{$certDir}/{$this->model->domain}.key";

            $system->exec("sudo mkdir -p {$certDir}");
            $system->exec("sudo cp {$serverCertFile} {$certFile}");
            $system->exec("sudo cp {$serverCertFile} {$caFile}");
            $system->exec("sudo cp {$serverCertFile} {$pemFile}");
            $system->exec("sudo cp {$serverKeyFile} {$keyFile}");
            $system->exec("sudo chmod 600 {$keyFile}");
            $system->exec("sudo chmod a+r {$certFile} {$caFile} {$pemFile}");
            $system->exec("sudo chown -R www-data:www-data {$certDir}");
            return;
        }

        $covering = (new EngineCertificate())->covering($this->model->domain);
        if ($covering !== null) {
            $this->putCertificate($covering['certificate'], $covering['key']);

            return;
        }

        Issuers::issueFor($this);
    }

    public function generateSelfSignedCertificate(): void
    {
        $certDir = "{$this->projectDirPath()}/ssl-certs";

        $certFile = "{$certDir}/{$this->model->domain}.crt";
        $keyFile = "{$certDir}/{$this->model->domain}.key";
        $caFile = "{$certDir}/{$this->model->domain}.ca";
        $pemFile = "{$certDir}/{$this->model->domain}.pem";

        $subj = "/C=US";
        $subj .= "/ST=California";
        $subj .= "/O=PanelAlpha";
        $subj .= "/OU=Org";
        $subj .= "/CN=" . $this->model->domain;

        $command = [
            'sudo',
            'openssl',
            'req',
            '-x509',
            '-newkey',
            'rsa:4096',
            '-keyout',
            $keyFile,
            '-out',
            $certFile,
            '-sha256',
            '-days',
            '365',
            '-nodes',
            '-subj',
            $subj,
        ];

        $this->system()->exec("sudo mkdir -p {$certDir}");
        $this->system()->exec($command);
        $this->system()->exec("sudo chmod 600 {$certFile}");
        $this->system()->exec("sudo cp {$certFile} {$caFile}");
        $this->system()->exec("sudo cp {$certFile} {$pemFile}");
        $this->system()->exec("sudo chmod a+r {$certFile} {$caFile} {$pemFile}");
    }

    public function hasSslCertificate(): bool
    {
        $certDir = "{$this->projectDirPath()}/ssl-certs";
        $certFile = "{$certDir}/{$this->model->domain}.crt";

        $process = $this->system()->runProcess(['test', '-e', $certFile]);
        if ($process->getExitCode() === 0) {
            return true;
        }

        $this->normalizeCertFileNames($certDir);

        $process = $this->system()->runProcess(['test', '-e', $certFile]);
        return $process->getExitCode() === 0;
    }

    /**
     * Whether the two files an nginx TLS block loads are on disk. A vhost naming
     * a missing one fails `nginx -t`, and with it every reload on the host.
     */
    public function hasServableCertificate(): bool
    {
        $certDir = "{$this->projectDirPath()}/ssl-certs";
        $system = $this->system();
        foreach (['pem', 'key'] as $ext) {
            $file = "{$certDir}/{$this->model->domain}.{$ext}";
            if ($system->runProcess(['test', '-e', $file])->getExitCode() !== 0) {
                return false;
            }
        }

        return true;
    }

    public function ensureServableCertificate(): bool
    {
        if ($this->hasServableCertificate()) {
            return true;
        }
        $this->normalizeCertFileNames("{$this->projectDirPath()}/ssl-certs");
        if ($this->hasServableCertificate()) {
            return true;
        }
        if (!is_dir($this->projectDirPath())) {
            return false;
        }

        try {
            $this->generateSelfSignedCertificate();
        } catch (\Throwable $e) {
            Log::warning(
                "Could not self-sign a certificate for {$this->model->domain}: " . $e->getMessage()
            );
        }

        return $this->hasServableCertificate();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSslCertificateInfo(): array
    {
        $certDir = "{$this->projectDirPath()}/ssl-certs";
        $certFile = "{$certDir}/{$this->model->domain}.crt";
        $caFile = "{$certDir}/{$this->model->domain}.ca";

        $cert = (string) file_get_contents($certFile);
        $ca = (string) file_get_contents($caFile);

        $facts = CertificateFacts::fromPem($cert, $ca) ?? [
            'common_name' => '',
            'issuer_name' => 'Unknown',
            'issuer_common_name' => '',
            'not_before' => null,
            'not_after' => null,
            'domains' => [],
            'chain_trusted' => null,
        ];

        return ['certificate' => $cert, 'cabundle' => $ca]
            + $facts
            + CertificateStatus::of($facts, $this->model->domain);
    }

    public function putCertificate(string $cert, string $key, string $ca = ''): void
    {
        if (empty($ca)) {
            $ca = $cert;
        }

        $certDir = "{$this->projectDirPath()}/ssl-certs";
        $certFile = "{$certDir}/{$this->model->domain}.crt";
        $keyFile = "{$certDir}/{$this->model->domain}.key";
        $caFile = "{$certDir}/{$this->model->domain}.ca";
        $pemFile = "{$certDir}/{$this->model->domain}.pem";

        $tmpCertFile = tempnam(sys_get_temp_dir(), 'tmp_');
        $tmpKeyFile = tempnam(sys_get_temp_dir(), 'tmp_');
        $tmpCaFile = tempnam(sys_get_temp_dir(), 'tmp_');
        $tmpPemFile = tempnam(sys_get_temp_dir(), 'tmp_');

        file_put_contents($tmpCertFile, trim($cert) . PHP_EOL);
        file_put_contents($tmpKeyFile, trim($key) . PHP_EOL);
        file_put_contents($tmpCaFile, trim($ca) . PHP_EOL);
        file_put_contents($tmpPemFile, trim($cert) . PHP_EOL . trim($ca) . PHP_EOL);

        $system = $this->system();
        $system->exec(['sudo', 'mkdir', '-p', $certDir]);

        $system->exec(['sudo', 'cp', $tmpCertFile, $certFile]);
        $system->exec(['sudo', 'cp', $tmpKeyFile, $keyFile]);
        $system->exec(['sudo', 'cp', $tmpCaFile, $caFile]);
        $system->exec(['sudo', 'cp', $tmpPemFile, $pemFile]);
        $system->exec(['sudo', 'chmod', 'a+r', $certFile, $caFile, $pemFile]);
        $system->exec(['sudo', 'chmod', '600', $keyFile]);

        unlink($tmpCertFile);
        unlink($tmpKeyFile);
        unlink($tmpCaFile);
        unlink($tmpPemFile);
    }

    /**
     * @return array<array{file: string, path: string, mtime: int, size: int}>
     */
    public function listLogFiles(): array
    {
        $webserver = $this->system()->webserver()->getCurrentWebserver();
        $domainName = $this->model->domain;
        $logsDir = $this->system()->engineDirPath() . "/webserver-logs/{$webserver}/{$domainName}";

        $files = [];
        $fileNames = [];
        foreach (scandir($logsDir) as $file) {
            if (Str::startsWith($file, ['access', 'error'])) {
                $fileNames[] = $file;
            }
        }

        foreach ($fileNames as $fileName) {
            $files[] = [
                'file' => $fileName,
                'path' => "{$logsDir}/{$fileName}",
                'mtime' => filemtime("{$logsDir}/{$fileName}"),
                'size' => filesize("{$logsDir}/{$fileName}"),
            ];
        }

        return $files;
    }

    /**
     * @return array<array{file: string, path: string, mtime: int, size: int}>
     */
    public function listWebserverLogFiles(): array
    {
        $files = [];

        $webserver = $this->system()->webserver()->getCurrentWebserver();
        $domainName = $this->model->domain;
        $logsDir = $this->system()->engineDirPath() . "/webserver-logs/{$webserver}/{$domainName}";

        $fileNames = [];
        if (is_dir($logsDir)) {
            foreach (scandir($logsDir) as $file) {
                if (Str::startsWith($file, ['access', 'error'])) {
                    $fileNames[] = $file;
                }
            }
            foreach ($fileNames as $fileName) {
                $files[] = [
                    'file' => $fileName,
                    'path' => "{$logsDir}/{$fileName}",
                    'mtime' => filemtime("{$logsDir}/{$fileName}"),
                    'size' => filesize("{$logsDir}/{$fileName}"),
                ];
                if ($webserver == 'nginx-proxy') {
                    $files[] = [
                        'file' => 'nginx_' . $fileName,
                        'path' => "{$logsDir}/{$fileName}",
                        'mtime' => filemtime("{$logsDir}/{$fileName}"),
                        'size' => filesize("{$logsDir}/{$fileName}"),
                    ];
                }
            }
        }

        $fileNames = [];
        $apacheLogsDir = $this->projectDirPath() . "/log/apache2";
        if (is_dir($apacheLogsDir)) {
            foreach (scandir($apacheLogsDir) as $file) {
                if (Str::startsWith($file, ['access', 'error'])) {
                    $fileNames[] = $file;
                }
            }
            foreach ($fileNames as $fileName) {
                $files[] = [
                    'file' => 'apache_' . $fileName,
                    'path' => "{$apacheLogsDir}/{$fileName}",
                    'mtime' => filemtime("{$apacheLogsDir}/{$fileName}"),
                    'size' => filesize("{$apacheLogsDir}/{$fileName}"),
                ];
            }
        }

        usort($files, function (array $a, array $b): int {
            return (int)$b['mtime'] - (int)$a['mtime'];
        });

        return $files;
    }

    public function createFromTemplate(): void
    {
        $domainName = $this->model->domain;

        $templateVars = [
            'user' => $this->userModel()->username,
            'domain' => $domainName,
            'php_version' => $this->model->getPhpVersion(),
        ];
        if ($this->model->sslEnabled()) {
            $certDir = "/etc/apache2/ssl-certs";
            $templateVars['ssl_enabled'] = true;
            $templateVars['ssl_cert_file'] = "{$certDir}/{$domainName}.crt";
            $templateVars['ssl_cert_key_file'] = "{$certDir}/{$domainName}.key";
            $templateVars['ssl_cert_ca_file'] = "{$certDir}/{$domainName}.ca";
        }

        $templatesDir = $this->system()->templatesDirPath();

        $this->createDomainRootDir();

        $templateVars['relative_document_root'] = $this->model->getDocumentRoot();
        $domainConfigFile = "{$this->projectDirPath()}/apache-sites/{$domainName}.conf";
        $templateConfigFile = "{$templatesDir}/virtualHost-apache-user.blade.php";
        $this->system()->filesystem()->makeFileFromTemplate($domainConfigFile, $templateConfigFile, $templateVars);
    }

    public function deleteApacheConfig(): void
    {
        $domainConfigFile = "{$this->projectDirPath()}/apache-sites/{$this->model->domain}.conf";
        $this->system()->exec("sudo rm {$domainConfigFile}");
    }

    public function deleteNginxConfig(): void
    {
        $domainName = $this->model->domain;
        $nginxProxy = new NginxProxy($this->system());
        if ($nginxProxy->domainConfigExists($domainName)) {
            $nginxProxy->deleteDomainConfig($domainName);
        }
    }

    public function publishCertificateToHostWebserver(): void
    {
        $this->hostWebserver()->rebuildDomainConfig($this->model);
        $this->hostWebserver()->reload();
    }

    private function projectDirPath(): string
    {
        return $this->system()->projectDirPath($this->project->username());
    }

    private function normalizeCertFileNames(string $certDir): void
    {
        $lowerDomain = $this->model->domain;
        $system = $this->system();

        foreach (['.crt', '.key', '.ca', '.pem'] as $ext) {
            $lowerFile = "{$certDir}/{$lowerDomain}{$ext}";

            if ($system->runProcess(['test', '-e', $lowerFile])->getExitCode() === 0) {
                continue;
            }

            $process = $system->runProcess(['sudo', 'find', $certDir, '-maxdepth', '1', '-iname', $lowerDomain . $ext]);
            if ($process->getExitCode() !== 0) {
                continue;
            }

            $found = trim($process->getOutput());
            if ($found === '' || $found === $lowerFile) {
                continue;
            }

            try {
                $system->exec(['sudo', 'mv', $found, $lowerFile]);
            } catch (\Exception $e) {
            }
        }
    }

    private function syncStatisticsConfig(): void
    {
        try {
            $this->statistics()->configureDomain(
                $this->model->domain,
                $this->model->getAliases(),
                $this->hostAccessLogDirectory(),
            );
        } catch (\Throwable $e) {
            Log::warning(
                "Statistics configure failed for {$this->model->domain}: " . $e->getMessage()
            );
        }
    }

    private function forgetStatistics(): void
    {
        try {
            $this->statistics()->forgetDomain($this->model->domain);
        } catch (\Throwable $e) {
            Log::warning(
                "Statistics cleanup during domain delete failed for {$this->model->domain}: " . $e->getMessage()
            );
        }
    }

    private function statistics(): Statistics
    {
        return app(Statistics::class);
    }

    private function hostAccessLogDirectory(): string
    {
        $webserver = $this->system()->webserver()->getCurrentWebserver();

        return $this->system()->engineDirPath() . '/webserver-logs/' . $webserver . '/' . $this->model->domain;
    }

    private function hostWebserver(): WebserverInterface
    {
        return $this->system()->webserver()->driver();
    }

    private function system(): System
    {
        return $this->project->system();
    }

    private function userModel(): ModelsUser
    {
        return $this->project->userModel();
    }
}
