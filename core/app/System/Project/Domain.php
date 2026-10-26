<?php

namespace App\System\Project;

use App\Integrations\Statistics\Statistics;
use App\Integrations\Tunnels\TunnelManager;
use App\Lib\HttpAcmeChallengeStore;
use App\Lib\Project\AccountFile;
use App\Lib\Project\PhpErrorLog;
use App\Lib\Ssl\CertificateFacts;
use App\Lib\Ssl\CertificateStatus;
use App\Lib\Ssl\EngineCertificate;
use App\Lib\Ssl\IssuableDomain;
use App\Lib\Ssl\Issuers;
use App\Models\Domain as DomainModel;
use App\Models\ProxyRule;
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
    ) {}

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
            $this->deleteAfterFailedCreate();
            throw $e;
        }
    }

    /** Best-effort: a cleanup failure is logged so it cannot replace the error that caused it. */
    private function deleteAfterFailedCreate(): void
    {
        try {
            $this->delete();
        } catch (\Throwable $cleanup) {
            Log::warning(
                "Cleanup after failed domain create failed for {$this->model->domain}: ".$cleanup->getMessage()
            );
        }
    }

    public function rebuild(): void
    {
        if ($this->model->sslEnabled() && ! $this->hasSslCertificate()) {
            $this->generateCertificate();
        }
        $this->project->rebuildDomain($this->model);
        $this->hostWebserver()->rebuildDomainConfig($this->model);
    }

    public function delete(): void
    {
        $this->tearDownTunnel();
        $this->deleteOwnProxyRules();
        $this->forgetStatistics();
        $this->deleteFromHostWebserver();

        $this->project->deleteDomainConfig($this->model->domain);
        $this->project->reloadWebserver();

        (new HttpAcmeChallengeStore($this->system()))->deleteDomainDir($this->model->domain);
    }

    private function tearDownTunnel(): void
    {
        try {
            TunnelManager::teardownDomain($this->userModel(), $this->model);
        } catch (\Throwable $e) {
            Log::warning(
                "Cloudflare teardown during domain delete failed for {$this->model->domain}: ".$e->getMessage()
            );
        }
    }

    /** Only this project's rules: another project's rule may name the same domain. */
    private function deleteOwnProxyRules(): void
    {
        try {
            ProxyRule::forUser($this->project->username())
                ->where('server_name', $this->model->domain)
                ->delete();
        } catch (\Throwable $e) {
            Log::warning(
                "ProxyRule cleanup during domain delete failed for {$this->model->domain}: ".$e->getMessage()
            );
        }
    }

    private function deleteFromHostWebserver(): void
    {
        $this->hostWebserver()->deleteDomainConfig($this->model->domain);
        if (! config('env.KEEP_WEBSERVER_LOGS_FOR_DELETED_DOMAINS')) {
            $this->hostWebserver()->deleteDomainLogsDir($this->model->domain);
        }
        $this->hostWebserver()->reload();
    }

    public function createDomainRootDir(): void
    {
        $user = $this->userModel();
        $owner = "{$user->getUid()}:{$user->getGid()}";

        $system = $this->system();
        $home = $system->projectHomeDirPath($this->project->username());
        $domainDir = $home.'/'.$this->model->domain;
        $domainRootDir = $home.$this->model->getDocumentRoot();

        $this->makeOwnedDir($domainDir, $owner);

        $documentRootMissing = ! $system->filesystem()->isDir($domainRootDir);
        $this->makeOwnedDir($domainRootDir, $owner);

        if ($documentRootMissing) {
            $this->fillDocumentRootFromTemplate($domainRootDir, $owner);
        }
    }

    private function makeOwnedDir(string $dir, string $owner): void
    {
        $this->system()->exec(['sudo', 'mkdir', '-p', $dir]);
        $this->system()->exec(['sudo', 'chown', $owner, $dir]);
    }

    private function fillDocumentRootFromTemplate(string $domainRootDir, string $owner): void
    {
        $templateVars = [
            'user' => $this->userModel()->username,
            'domain' => $this->model->domain,
        ];
        $this->system()->filesystem()->makeDirFromTemplate(
            $domainRootDir,
            $this->documentRootTemplateDir(),
            $templateVars,
            $owner
        );
        $this->system()->exec(['sudo', 'chmod', '-R', 'ugo-rwx,u+rwX,go+rX', $domainRootDir]);
    }

    /** The project template's `public_html`, or the engine's default site when it ships none. */
    private function documentRootTemplateDir(): string
    {
        $system = $this->system();
        $template = $this->userModel()->getTemplate() ?? 'default';
        $templateDir = $system->projectDomainTemplateDirPath($template).'/public_html';
        if (! $system->filesystem()->isDir($templateDir)) {
            $templateDir = $system->templatesDirPath().'/user-home/default-site';
        }

        return $templateDir;
    }

    public function generateCertificate(): void
    {
        if ($this->model->domain == Setting::get('vhost-default-ip-domain')) {
            $this->copyEngineCertificate();

            return;
        }

        $covering = (new EngineCertificate)->covering($this->model->domain);
        if ($covering !== null) {
            $this->putCertificate($covering['certificate'], $covering['key']);

            return;
        }

        Issuers::issueFor($this);
    }

    /** The domain that answers for the server's bare IP serves the engine's own certificate. */
    private function copyEngineCertificate(): void
    {
        $system = $this->system();
        $engineDir = $system->engineDirPath();
        $serverCertFile = $engineDir.'/crt/server.cert';
        $serverKeyFile = $engineDir.'/crt/server.key';

        $certDir = $this->certDir();
        $certFile = $this->certFile('crt');
        $caFile = $this->certFile('ca');
        $pemFile = $this->certFile('pem');
        $keyFile = $this->certFile('key');

        $system->exec(['sudo', 'mkdir', '-p', $certDir]);
        $system->exec(['sudo', 'cp', $serverCertFile, $certFile]);
        $system->exec(['sudo', 'cp', $serverCertFile, $caFile]);
        $system->exec(['sudo', 'cp', $serverCertFile, $pemFile]);
        $system->exec(['sudo', 'cp', $serverKeyFile, $keyFile]);
        $system->exec(['sudo', 'chmod', '600', $keyFile]);
        $system->exec(['sudo', 'chmod', 'a+r', $certFile, $caFile, $pemFile]);
        $system->exec(['sudo', 'chown', '-R', 'www-data:www-data', $certDir]);
    }

    public function generateSelfSignedCertificate(): void
    {
        $certDir = $this->certDir();
        $certFile = $this->certFile('crt');
        $keyFile = $this->certFile('key');
        $caFile = $this->certFile('ca');
        $pemFile = $this->certFile('pem');

        $this->system()->exec(['sudo', 'mkdir', '-p', $certDir]);
        $this->system()->exec($this->selfSignCommand($keyFile, $certFile));
        $this->system()->exec(['sudo', 'chmod', '600', $certFile]);
        $this->system()->exec(['sudo', 'cp', $certFile, $caFile]);
        $this->system()->exec(['sudo', 'cp', $certFile, $pemFile]);
        $this->system()->exec(['sudo', 'chmod', 'a+r', $certFile, $caFile, $pemFile]);
    }

    /**
     * @return list<string>
     */
    private function selfSignCommand(string $keyFile, string $certFile): array
    {
        $subject = '/C=US/ST=California/O=PanelAlpha/OU=Org/CN='.$this->model->domain;

        return [
            'sudo', 'openssl', 'req', '-x509',
            '-newkey', 'rsa:4096',
            '-keyout', $keyFile,
            '-out', $certFile,
            '-sha256',
            '-days', '365',
            '-nodes',
            '-subj', $subject,
        ];
    }

    public function hasSslCertificate(): bool
    {
        if ($this->fileExists($this->certFile('crt'))) {
            return true;
        }

        $this->normalizeCertFileNames($this->certDir());

        return $this->fileExists($this->certFile('crt'));
    }

    /**
     * Whether the two files an nginx TLS block loads are on disk. A vhost naming
     * a missing one fails `nginx -t`, and with it every reload on the host.
     */
    public function hasServableCertificate(): bool
    {
        foreach (['pem', 'key'] as $ext) {
            if (! $this->fileExists($this->certFile($ext))) {
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
        $this->normalizeCertFileNames($this->certDir());
        if ($this->hasServableCertificate()) {
            return true;
        }
        if (! is_dir($this->projectDirPath())) {
            return false;
        }

        try {
            $this->generateSelfSignedCertificate();
        } catch (\Throwable $e) {
            Log::warning(
                "Could not self-sign a certificate for {$this->model->domain}: ".$e->getMessage()
            );
        }

        return $this->hasServableCertificate();
    }

    /**
     * @return array<string, mixed>
     */
    public function getSslCertificateInfo(): array
    {
        $cert = (string) file_get_contents($this->certFile('crt'));
        $ca = (string) file_get_contents($this->certFile('ca'));

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

        $certFile = $this->certFile('crt');
        $keyFile = $this->certFile('key');
        $caFile = $this->certFile('ca');
        $pemFile = $this->certFile('pem');

        $system = $this->system();
        $system->exec(['sudo', 'mkdir', '-p', $this->certDir()]);

        $this->installCertFile(trim($cert).PHP_EOL, $certFile);
        $this->installCertFile(trim($key).PHP_EOL, $keyFile);
        $this->installCertFile(trim($ca).PHP_EOL, $caFile);
        $this->installCertFile(trim($cert).PHP_EOL.trim($ca).PHP_EOL, $pemFile);
        $system->exec(['sudo', 'chmod', 'a+r', $certFile, $caFile, $pemFile]);
        $system->exec(['sudo', 'chmod', '600', $keyFile]);
    }

    /** Stages the contents in a temp file the engine can write, then copies it in with sudo. */
    private function installCertFile(string $contents, string $target): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        file_put_contents($tmpFile, $contents);
        $this->system()->exec(['sudo', 'cp', $tmpFile, $target]);
        unlink($tmpFile);
    }

    /**
     * @return array<array{file: string, path: string, mtime: int, size: int}>
     */
    public function listLogFiles(): array
    {
        $logsDir = $this->hostLogsDir($this->system()->webserver()->getCurrentWebserver());

        $files = [];
        foreach (self::logFileNames($logsDir) as $fileName) {
            $files[] = self::logFileEntry($fileName, "{$logsDir}/{$fileName}");
        }

        return $files;
    }

    /**
     * Host webserver logs (nginx-proxy ones listed twice, the second as `nginx_*`)
     * and the project's Apache logs, newest first.
     *
     * @return array<array{file: string, path: string, mtime: int, size: int}>
     */
    public function listWebserverLogFiles(): array
    {
        $files = array_merge($this->hostWebserverLogFiles(), $this->apacheLogFiles(), $this->phpErrorLogFiles());

        usort($files, function (array $a, array $b): int {
            return (int) $b['mtime'] - (int) $a['mtime'];
        });

        return $files;
    }

    /**
     * The PHP handlers write one error log for the whole project; it is
     * offered beside the webserver logs so the existing download serves it.
     *
     * @return list<array{file: string, path: string, mtime: int, size: int}>
     */
    private function phpErrorLogFiles(): array
    {
        $path = $this->projectDirPath().'/log/php/'.PhpErrorLog::FILE_NAME;
        $stat = AccountFile::ownedRegular($path, $this->userModel()->getUid());
        if ($stat === null) {
            return [];
        }

        return [[
            'file' => PhpErrorLog::FILE_NAME,
            'path' => $path,
            'mtime' => (int) $stat['mtime'],
            'size' => (int) $stat['size'],
        ]];
    }

    /**
     * @return list<array{file: string, path: string, mtime: int, size: int}>
     */
    private function hostWebserverLogFiles(): array
    {
        $webserver = $this->system()->webserver()->getCurrentWebserver();
        $logsDir = $this->hostLogsDir($webserver);

        $files = [];
        foreach (self::logFileNames($logsDir) as $fileName) {
            $files[] = self::logFileEntry($fileName, "{$logsDir}/{$fileName}");
            if ($webserver == 'nginx-proxy') {
                $files[] = self::logFileEntry('nginx_'.$fileName, "{$logsDir}/{$fileName}");
            }
        }

        return $files;
    }

    /**
     * @return list<array{file: string, path: string, mtime: int, size: int}>
     */
    private function apacheLogFiles(): array
    {
        $apacheLogsDir = $this->projectDirPath().'/log/apache2';

        $files = [];
        foreach (self::logFileNames($apacheLogsDir) as $fileName) {
            $files[] = self::logFileEntry('apache_'.$fileName, "{$apacheLogsDir}/{$fileName}");
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function logFileNames(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $fileNames = [];
        foreach (scandir($dir) as $file) {
            if (Str::startsWith($file, ['access', 'error'])) {
                $fileNames[] = $file;
            }
        }

        return $fileNames;
    }

    /**
     * @return array{file: string, path: string, mtime: int, size: int}
     */
    private static function logFileEntry(string $label, string $path): array
    {
        return [
            'file' => $label,
            'path' => $path,
            'mtime' => filemtime($path),
            'size' => filesize($path),
        ];
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
            $templateVars += self::apacheSslTemplateVars($domainName);
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
        $this->system()->exec(['sudo', 'rm', $domainConfigFile]);
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

    /**
     * Paths of the certificate as the project's Apache container mounts them.
     *
     * @return array{ssl_enabled: true, ssl_cert_file: string, ssl_cert_key_file: string, ssl_cert_ca_file: string}
     */
    private static function apacheSslTemplateVars(string $domainName): array
    {
        $certDir = '/etc/apache2/ssl-certs';

        return [
            'ssl_enabled' => true,
            'ssl_cert_file' => "{$certDir}/{$domainName}.crt",
            'ssl_cert_key_file' => "{$certDir}/{$domainName}.key",
            'ssl_cert_ca_file' => "{$certDir}/{$domainName}.ca",
        ];
    }

    private function projectDirPath(): string
    {
        return $this->system()->projectDirPath($this->project->username());
    }

    private function certDir(): string
    {
        return "{$this->projectDirPath()}/ssl-certs";
    }

    private function certFile(string $ext): string
    {
        return "{$this->certDir()}/{$this->model->domain}.{$ext}";
    }

    private function fileExists(string $path): bool
    {
        return $this->system()->runProcess(['test', '-e', $path])->getExitCode() === 0;
    }

    /** Renames cert files stored under a differently-cased domain to the name the vhost loads. */
    private function normalizeCertFileNames(string $certDir): void
    {
        foreach (['.crt', '.key', '.ca', '.pem'] as $ext) {
            $this->normalizeCertFileName($certDir, $this->model->domain.$ext);
        }
    }

    /** Best-effort: a failed rename leaves the file where it was. */
    private function normalizeCertFileName(string $certDir, string $fileName): void
    {
        $expected = "{$certDir}/{$fileName}";
        if ($this->fileExists($expected)) {
            return;
        }

        $process = $this->system()->runProcess(['sudo', 'find', $certDir, '-maxdepth', '1', '-iname', $fileName]);
        if ($process->getExitCode() !== 0) {
            return;
        }

        $found = trim($process->getOutput());
        if ($found === '' || $found === $expected) {
            return;
        }

        try {
            $this->system()->exec(['sudo', 'mv', $found, $expected]);
        } catch (\Exception) {
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
                "Statistics configure failed for {$this->model->domain}: ".$e->getMessage()
            );
        }
    }

    private function forgetStatistics(): void
    {
        try {
            $this->statistics()->forgetDomain($this->model->domain);
        } catch (\Throwable $e) {
            Log::warning(
                "Statistics cleanup during domain delete failed for {$this->model->domain}: ".$e->getMessage()
            );
        }
    }

    private function statistics(): Statistics
    {
        return app(Statistics::class);
    }

    private function hostAccessLogDirectory(): string
    {
        return $this->hostLogsDir($this->system()->webserver()->getCurrentWebserver());
    }

    private function hostLogsDir(string $webserver): string
    {
        return $this->system()->engineDirPath()."/webserver-logs/{$webserver}/{$this->model->domain}";
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
