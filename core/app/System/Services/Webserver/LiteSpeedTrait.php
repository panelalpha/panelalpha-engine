<?php

namespace App\System\Services\Webserver;

use App\Exceptions\DockerErrorException;
use App\Models\Domain;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait LiteSpeedTrait
{
    public function createDomainConfig(Domain $domain): void
    {
        $this->rebuildConfig();
    }

    private function getVersion(): string
    {
        try {
            $ver = $this->system->exec([
                'sudo',
                'docker',
                'compose',
                '-f',
                $this->system->composeFilePath(),
                'exec',
                'sites-http',
                'cat',
                '/usr/local/lsws/VERSION',
            ]);

            return trim($ver);
        } catch (\Exception $e) {
            return 'Unknown';
        }
    }

    public function resetWebPanelPassword(): string
    {
        $newPassword = Str::random(16);

        $adminDirPath = '/usr/local/lsws/admin';
        $phpBinPath = "{$adminDirPath}/fcgi-bin/admin_php";
        $phpBinPathAlt = "{$adminDirPath}/fcgi-bin/admin_php5";
        $phpScriptPath = "{$adminDirPath}/misc/htpasswd.php";
        $passwdPath = "{$adminDirPath}/conf/htpasswd";

        $bashScript = <<<EOS
if [ -e {$phpBinPath} ]; then
  echo "admin:\$({$phpBinPath} -q {$phpScriptPath} \$ADMIN_PASS)" > {$passwdPath};
else
  echo "admin:\$({$phpBinPathAlt} -q {$phpScriptPath} \$ADMIN_PASS)" > {$passwdPath};
fi
EOS;

        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            '-e',
            'ADMIN_PASS='.$newPassword,
            'sites-http',
            'bash',
            '-c',
            $bashScript,
        ]);

        return $newPassword;
    }

    public function validateSerialNumberForConfig(string $serialNumber): void
    {
        $this->validateSerialNumber($serialNumber);
    }

    public function updateConfig(string $name, string $value): void
    {
        if ($name !== 'serial_number') {
            throw new \Exception('Unsupported config entry for current webserver');
        }

        $this->validateSerialNumber($value);
        $this->updateSerialNumber($value);
    }

    private function getSerialNumber(): string
    {
        $filePath = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug().'/serial.no';
        if (! $this->system->filesystem()->fileExists($filePath)) {
            return '';
        }

        return $this->system->filesystem()->fileGetContents($filePath);
    }

    private function validateSerialNumber(string $serialNumber): void
    {
        $configDir = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug();
        $this->system->filesystem()->filePutContents($configDir.'/serial.no.tmp', $serialNumber, '994:994');
        try {
            $this->system->exec([
                'sudo',
                'docker',
                'run',
                '--rm',
                '-v',
                "{$configDir}/serial.no.tmp:/usr/local/lsws/conf/serial.no",
                '--entrypoint',
                '/usr/local/lsws/bin/lshttpd',
                'litespeedtech/litespeed',
                '-r',
            ]);
        } catch (DockerErrorException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('LiteSpeed serial number validation failed', [
                'error' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'serial_number' => 'The LiteSpeed serial number is invalid.',
            ]);
        }
    }

    private function updateSerialNumber(string $serialNumber): void
    {
        $configDir = $this->system->engineDirPath().'/webserver-config/'.$this->getSlug();
        $configFiles = $this->system->filesystem()->ls($configDir);

        if (! in_array('serial.no', $configFiles)) {
            $this->system->filesystem()->filePutContents($configDir.'/serial.no', $serialNumber, '994:994');
            $this->reload();

            return;
        }

        $backupFileSuffix = '.bak'.time();
        $moveFiles = [
            "{$configDir}/serial.no" => "{$configDir}/serial.no{$backupFileSuffix}",
            "{$configDir}/license.key" => "{$configDir}/license.key{$backupFileSuffix}",
            "{$configDir}/trial.key" => "{$configDir}/trial.key{$backupFileSuffix}",
        ];

        foreach ($moveFiles as $from => $to) {
            $this->system->runProcess([
                'sudo',
                'mv',
                $from,
                $to,
            ]);
        }

        $this->system->filesystem()->filePutContents($configDir.'/serial.no', $serialNumber, '994:994');
        $this->system->exec([
            'sudo',
            'docker',
            'compose',
            '-f',
            $this->system->composeFilePath(),
            'exec',
            'sites-http',
            '/usr/local/lsws/bin/lshttpd',
            '-r',
        ]);
        $this->reload();

        $keepBackups = [];
        $removeBackups = [];
        foreach ($configFiles as $filename) {

            if (! Str::startsWith($filename, [
                'serial.no.bak',
                'license.key.bak',
                'trial.key.bak',
            ])) {
                continue;
            }
            $suffix = Str::afterLast($filename, 'serial.no.bak');
            if (! $suffix) {
                continue;
            }
            if (count($keepBackups) < 10) {
                if (! in_array($suffix, $keepBackups)) {
                    $keepBackups[] = $suffix;
                }

                continue;
            }
            $removeBackups[] = $suffix;
        }
        foreach ($removeBackups as $removeSuffix) {
            $this->system->runProcess(['sudo', 'rm', "{$configDir}/serial.no.bak{$removeSuffix}"]);
            $this->system->runProcess(['sudo', 'rm', "{$configDir}/license.key.bak{$removeSuffix}"]);
            $this->system->runProcess(['sudo', 'rm', "{$configDir}/trial.key.bak{$removeSuffix}"]);
        }
    }

    public function applyCloudflareRealIpConfig(): void
    {
        if ($this instanceof Openlitespeed) {
            $this->applyOpenlitespeedCloudflareRealIpConfig();

            return;
        }
        if ($this instanceof Litespeed) {
            $this->applyLitespeedCloudflareRealIpConfig();
        }
    }

    private function applyOpenlitespeedCloudflareRealIpConfig(): void
    {
        $snippetPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/cloudflare-realip.conf';
        if (! $this->system->filesystem()->fileExists($snippetPath)) {
            return;
        }

        $snippet = trim($this->system->filesystem()->fileGetContents($snippetPath));
        $configPath = $this->system->engineDirPath().'/webserver-config/openlitespeed/httpd_config.conf';
        $config = $this->system->filesystem()->fileGetContents($configPath);
        $config = preg_replace(
            '/# panelalpha-cloudflare-realip-begin.*?# panelalpha-cloudflare-realip-end\s*/s',
            '',
            $config
        ) ?? $config;
        $config = trim($config)."\n\n".$snippet."\n";
        $this->system->filesystem()->filePutContents($configPath, $config);
    }

    private function applyLitespeedCloudflareRealIpConfig(): void
    {
        $snippetPath = $this->system->engineDirPath().'/webserver-config/litespeed/cloudflare-realip.xml';
        if (! $this->system->filesystem()->fileExists($snippetPath)) {
            return;
        }

        $snippet = $this->system->filesystem()->fileGetContents($snippetPath);
        $innerSnippet = preg_replace(
            '/<!-- panelalpha-cloudflare-realip-begin -->|<!-- panelalpha-cloudflare-realip-end -->/s',
            '',
            $snippet
        );
        if ($innerSnippet === null) {
            return;
        }
        $innerSnippet = trim($innerSnippet);

        $configPath = $this->system->engineDirPath().'/webserver-config/litespeed/httpd_config.xml';
        $config = $this->system->filesystem()->fileGetContents($configPath);
        $config = preg_replace(
            '/<!-- panelalpha-cloudflare-realip-begin -->.*?<!-- panelalpha-cloudflare-realip-end -->\s*/s',
            '',
            $config
        ) ?? $config;

        $replacement = "<!-- panelalpha-cloudflare-realip-begin -->\n{$innerSnippet}\n<!-- panelalpha-cloudflare-realip-end -->\n";
        $newConfig = preg_replace(
            '/(<httpServerConfig>\s*)/',
            '$1'.$replacement,
            $config,
            1
        );
        if ($newConfig === null) {
            throw new \Exception('Could not inject Cloudflare real IP config into LiteSpeed httpd_config.xml');
        }

        $this->system->filesystem()->filePutContents($configPath, $newConfig);
    }
}
