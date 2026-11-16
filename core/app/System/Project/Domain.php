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
use App\Lib\Ssl\KeptCertificate;
use App\Models\Domain as DomainModel;
use App\Models\ProxyRule;
use App\Models\Setting;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Privileged;
use App\System\Privileged\AccountFiles;
use App\System\Privileged\Exchange;
use App\System\Project as UserProject;
use App\System\Services\Webserver\NginxProxy;
use App\System\Services\Webserver\WebserverInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class Domain implements IssuableDomain
{
    /** OpenSSL refuses a longer subject CN (RFC 5280's ub-common-name). */
    private const COMMON_NAME_MAX = 64;

    /** The organisation of the certificate the engine signs itself. */
    private const SELF_SIGNED_ORGANISATION = 'PanelAlpha';

    /** Beside the certificate files, the mark that an authority issued them through the engine. */
    private const ISSUED_MARK = 'issued';

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

    /**
     * The name keeps a certificate only when a rename kept it moments ago ({@see KeptCertificate}); any other material
     * left under the name is discarded, so a name added again later never serves a certificate it had once.
     */
    public function create(): void
    {
        $carried = $this->carriesKeptCertificate();
        try {
            if (! $carried) {
                $this->replaceLeftoverCertificate();
            }

            $this->createDomainRootDir();
            $this->routeToApp();

            $this->project->createDomainConfig($this->model);
            $this->project->reloadWebserver();

            $this->hostWebserver()->addDomain($this->model);
            $this->hostWebserver()->reload(false);
            $this->syncStatisticsConfig();
        } catch (\Exception $e) {
            $this->deleteAfterFailedCreate(keepCertificate: $carried);
            throw $e;
        }
    }

    private function carriesKeptCertificate(): bool
    {
        return KeptCertificate::isKeptFor($this->userModel(), $this->model->domain) && $this->hasSslCertificate();
    }

    private function replaceLeftoverCertificate(): void
    {
        if ($this->hasSslCertificate()) {
            $this->deleteCertificates();
        }
        if ($this->model->sslEnabled()) {
            $this->generateCertificate();
        }
    }

    /** A container project's new domain answers with the app, on the port its other domains are served from. */
    private function routeToApp(): void
    {
        $user = $this->userModel();
        if ($user->getTemplate() !== 'dind') {
            return;
        }
        $upstream = ProxyRule::generatedUpstreamOf($user->username);
        if ($upstream !== null) {
            ProxyRule::ensureGeneratedHttpPair($user->username, $this->model->domain, $upstream['port'], $upstream['protocol']);
        }
    }

    /**
     * Best-effort: a cleanup failure is logged so it cannot replace the error that caused it. A certificate the name
     * carried in is kept, since this create did not make it.
     */
    private function deleteAfterFailedCreate(bool $keepCertificate): void
    {
        try {
            $this->delete($keepCertificate);
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

    /**
     * @param  bool  $keepCertificate  The name keeps the certificate material it has, which a rename needs: the name may
     *                                 be added again and must keep the certificate installed on it.
     */
    public function delete(bool $keepCertificate = false): void
    {
        $this->tearDownTunnel();
        $this->deleteOwnProxyRules();
        $this->forgetStatistics();
        $this->deleteFromHostWebserver();

        $this->project->deleteDomainConfig($this->model->domain);
        $this->project->reloadWebserver();

        if (! $keepCertificate) {
            $this->deleteCertificates();
        }
        (new HttpAcmeChallengeStore($this->system()))->deleteDomainDir($this->model->domain);
    }

    /** The vhosts are gone, so the certificate material the engine installed for this name has no reader left. */
    private function deleteCertificates(): void
    {
        $this->system()->exec(Privileged::files()->removeIfExists(
            $this->certFile('crt'),
            $this->certFile('key'),
            $this->certFile('ca'),
            $this->certFile('pem'),
            $this->certFile(self::ISSUED_MARK),
        ));
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
        [$uid, $gid] = $this->userModel()->linuxIds();

        $system = $this->system();
        $home = $system->projectHomeDirPath($this->project->username());
        $domainDir = $home.'/'.$this->model->domain;
        $domainRootDir = $home.$this->model->getDocumentRoot();

        $this->makeAccountDir($home, $domainDir, $uid, $gid);

        $documentRootMissing = ! $system->filesystem()->isDir($domainRootDir);
        $this->makeAccountDir($home, $domainRootDir, $uid, $gid);

        if ($documentRootMissing) {
            $this->fillDocumentRootFromTemplate($domainRootDir, Privileged::accountFiles($uid, $gid));
        }
    }

    /**
     * Root makes only the entry in the root-owned home and the account the rest, so a planted link reaches only its files.
     */
    private function makeAccountDir(string $home, string $dir, int $uid, int $gid): void
    {
        $entry = HomeEntry::under($home, substr($dir, strlen($home)));
        if ($entry === null) {
            return;
        }
        $this->system()->exec(Privileged::scripts()->makeHomeEntry($entry, '0755', $uid.':'.$gid));
        $this->system()->exec(Privileged::accountFiles($uid, $gid)->makeDirectories($dir));
    }

    private function fillDocumentRootFromTemplate(string $domainRootDir, AccountFiles $account): void
    {
        $templateVars = [
            'user' => $this->userModel()->username,
            'domain' => $this->model->domain,
        ];
        $this->system()->filesystem()->makeDirFromTemplateAsAccount(
            $account,
            $domainRootDir,
            $this->documentRootTemplateDir(),
            $templateVars,
        );
        $this->system()->exec($account->changeModeRecursively('ugo-rwx,u+rwX,go+rX', $domainRootDir));
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

    /**
     * Replaces whatever certificate this domain carries with the one a new domain gets, and forgets the ACME
     * challenge state of the old one. Overwritten, not removed first, so a failure leaves the site its certificate.
     */
    public function resetCertificate(): void
    {
        $this->generateCertificate();
        (new HttpAcmeChallengeStore($this->system()))->deleteDomainDir($this->model->domain);

        $this->project->reloadWebserver();
        $this->hostWebserver()->reload();
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

        $system->exec(Privileged::files()->makeDirectories($certDir));
        $system->exec(Privileged::files()->copy($serverCertFile, $certFile));
        $system->exec(Privileged::files()->copy($serverCertFile, $caFile));
        $system->exec(Privileged::files()->copy($serverCertFile, $pemFile));
        $system->exec(Privileged::files()->copy($serverKeyFile, $keyFile));
        $system->exec(Privileged::files()->changeMode('600', $keyFile));
        $system->exec(Privileged::files()->changeMode('a+r', $certFile, $caFile, $pemFile));
        $system->exec(Privileged::files()->changeOwnerRecursively('www-data:www-data', $certDir));
    }

    public function generateSelfSignedCertificate(): void
    {
        $certDir = $this->certDir();
        $certFile = $this->certFile('crt');
        $keyFile = $this->certFile('key');
        $caFile = $this->certFile('ca');
        $pemFile = $this->certFile('pem');

        $this->system()->exec(Privileged::files()->makeDirectories($certDir));
        $this->system()->exec($this->selfSignCommand($keyFile, $certFile));
        $this->system()->exec(Privileged::files()->changeMode('600', $certFile));
        $this->system()->exec(Privileged::files()->copy($certFile, $caFile));
        $this->system()->exec(Privileged::files()->copy($certFile, $pemFile));
        $this->system()->exec(Privileged::files()->changeMode('a+r', $certFile, $caFile, $pemFile));
    }

    /**
     * The name is in subjectAltName, which clients match and which has no length
     * limit; it is the CN too only when it fits there.
     *
     * @return list<string>
     */
    private function selfSignCommand(string $keyFile, string $certFile): array
    {
        return Privileged::account()->selfSignedCertificate($keyFile, $certFile, $this->selfSignSubject(), $this->model->domain);
    }

    private function selfSignSubject(): string
    {
        $subject = '/C=US/ST=California/O='.self::SELF_SIGNED_ORGANISATION.'/OU=Org';
        $name = $this->model->domain;

        return strlen($name) <= self::COMMON_NAME_MAX ? $subject.'/CN='.$name : $subject;
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
        $system->exec(Privileged::files()->makeDirectories($this->certDir()));

        $this->installCertFile(trim($cert).PHP_EOL, $certFile);
        $this->installCertFile(trim($key).PHP_EOL, $keyFile);
        $this->installCertFile(trim($ca).PHP_EOL, $caFile);
        $this->installCertFile(trim($cert).PHP_EOL.trim($ca).PHP_EOL, $pemFile);
        $system->exec(Privileged::files()->changeMode('a+r', $certFile, $caFile, $pemFile));
        $system->exec(Privileged::files()->changeMode('600', $keyFile));
    }

    /** What an authority issued through the engine, marked so a removal of an installed certificate leaves it alone. */
    public function putIssuedCertificate(string $cert, string $key, string $ca = ''): void
    {
        $this->putCertificate($cert, $key, $ca);
        $this->installCertFile(self::ISSUED_MARK.PHP_EOL, $this->certFile(self::ISSUED_MARK));
    }

    /** A certificate somebody installed: the engine's issued mark goes, as the engine did not make it. */
    public function installCertificate(string $cert, string $key, string $ca = ''): void
    {
        $this->putCertificate($cert, $key, $ca);
        $this->system()->exec(Privileged::files()->removeIfExists($this->certFile(self::ISSUED_MARK)));
    }

    /**
     * Whether the name is on a certificate the engine made: one an authority issued through it, one it signed itself,
     * or the server's own that covers the name. Anything else was installed.
     */
    public function carriesEngineCertificate(): bool
    {
        if (! $this->hasSslCertificate()) {
            return false;
        }
        if ($this->fileExists($this->certFile(self::ISSUED_MARK))) {
            return true;
        }
        $info = $this->getSslCertificateInfo();

        return $this->isSelfSignedByTheEngine($info) || $this->isTheServersCertificate((string) $info['certificate']);
    }

    /** @param  array<string, mixed>  $info  what {@see getSslCertificateInfo()} answers */
    private function isSelfSignedByTheEngine(array $info): bool
    {
        return ($info['self_signed'] ?? false) === true && ($info['issuer_name'] ?? null) === self::SELF_SIGNED_ORGANISATION;
    }

    private function isTheServersCertificate(string $certificate): bool
    {
        $covering = (new EngineCertificate)->covering($this->model->domain);

        return $covering !== null && trim($covering['certificate']) === trim($certificate);
    }

    /** Stages the contents in a temp file the engine can write, then copies it in as root. */
    private function installCertFile(string $contents, string $target): void
    {
        $tmpFile = Exchange::file('tmp_');
        file_put_contents($tmpFile, $contents);
        $this->system()->exec(Privileged::files()->copy($tmpFile, $target));
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
        $this->system()->exec(Privileged::files()->remove($domainConfigFile));
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

        $process = $this->system()->runProcess(Privileged::files()->findInDirectoryIgnoringCase($certDir, $fileName));
        if ($process->getExitCode() !== 0) {
            return;
        }

        $found = trim($process->getOutput());
        if ($found === '' || $found === $expected) {
            return;
        }

        try {
            $this->system()->exec(Privileged::files()->move($found, $expected));
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
