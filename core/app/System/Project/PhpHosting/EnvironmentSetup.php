<?php

namespace App\System\Project\PhpHosting;

use App\System\Project\PhpHosting;

final class EnvironmentSetup
{
    public function createFromTemplate(PhpHosting $project, PhpStack $stack): void
    {
        $system = $project->system();
        $projectDir = $system->projectDirPath($project->username());

        $templateVars = $this->templateVars($project);
        $templatesDir = $system->projectFilesTemplateDirPath($project->userModel()->getTemplate());
        $system->filesystem()->makeDirFromTemplate($projectDir, $templatesDir, $templateVars, null, null, ['crontabs/www-data']);
        $this->selectStackFiles($project, $stack, $projectDir);

        $stack->applySettings($project);
        $this->writeServices($project, $stack);

        $system->exec("sudo chown -R www-data:www-data {$projectDir}");

        (new PhpErrorLogConfig())->apply($project);

        $this->setupCrontabFile($project);
    }

    public function applyRedisSettings(PhpHosting $project): void
    {
        $system = $project->system();
        $projectDir = $system->projectDirPath($project->username());
        $confFile = "{$projectDir}/redis/conf.d/custom.conf";
        $conf = $this->redisConf($project->userModel()->getRedisConfig());

        $system->runProcess("sudo mkdir -p {$projectDir}/redis/conf.d");

        $tmpFile = tempnam(sys_get_temp_dir(), 'tmp_');
        file_put_contents($tmpFile, $conf);
        $process = $system->runProcess(['sudo', 'cp', $tmpFile, $confFile]);
        unlink($tmpFile);
        if ($process->getExitCode() !== 0) {
            throw new \Exception("Could not update '{$confFile}': " . ($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    /**
     * @return array<string, string>
     */
    public function baseEntrypointInitScripts(PhpHosting $project): array
    {
        $userModel = $project->userModel();
        $username = $userModel->username;
        [$uid] = $userModel->linuxIds();
        $passwdEntryScript = <<<BASH
if getent passwd {$uid} >/dev/null 2>&1 || getent passwd {$username} >/dev/null 2>&1; then
  echo "[init] passwd entry already exists for UID={$uid} or user={$username}, skipping"
else
  echo "[init] adding /etc/passwd entry for UID={$uid}"
  useradd --uid {$uid} --home-dir /home/{$username} --shell /usr/sbin/nologin {$username}
fi
BASH;

        return ['useradd.sh' => $passwdEntryScript];
    }

    /**
     * @return array<string, mixed>
     */
    private function templateVars(PhpHosting $project): array
    {
        $system = $project->system();
        $user = $project->userModel();
        $deviceReadBps = $user->getDeviceReadBps();
        $deviceWriteBps = $user->getDeviceWriteBps();
        $blockDevice = '';
        if ($deviceReadBps !== null || $deviceWriteBps !== null) {
            $blockDevice = $system->filesystem()->getHomeFilesystemParentBlockDevice();
        }

        [$uid, $gid] = $user->linuxIds();

        return [
            'user' => $user->username,
            'uid' => $uid,
            'gid' => $gid,
            'cpu_limit' => (string) $user->getCpuLimit(),
            'memory_limit' => (string) $user->effectiveMemoryLimit(),
            'device_read_bps' => $deviceReadBps,
            'device_write_bps' => $deviceWriteBps,
            'block_device' => $blockDevice,
            'php_versions' => $system->php()->listAvailablePhpVersions(),
        ];
    }

    /** Copies the stack's Dockerfile and compose template over the names Docker reads. */
    private function selectStackFiles(PhpHosting $project, PhpStack $stack, string $projectDir): void
    {
        $system = $project->system();
        $system->exec("sudo cp {$projectDir}/{$stack->dockerfileTemplateName()} {$projectDir}/Dockerfile");
        $system->exec("sudo cp {$projectDir}/{$stack->composeTemplateName()} {$projectDir}/docker-compose.yml");
    }

    private function writeServices(PhpHosting $project, PhpStack $stack): void
    {
        $manager = $project->services();
        $services = $stack->services($project);
        $this->setupEntrypointInitScripts($project, $stack, $manager->bootScripts($services));
        $manager->write($services);
    }

    /**
     * @param array<string, string> $settings
     */
    private function redisConf(array $settings): string
    {
        $lines = [];
        foreach ($settings as $key => $value) {
            $lines[] = "{$key} {$value}";
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $bootScripts
     */
    private function setupEntrypointInitScripts(PhpHosting $project, PhpStack $stack, array $bootScripts): void
    {
        $scriptFiles = array_merge(
            $this->baseEntrypointInitScripts($project),
            $stack->entrypointInitScripts($project),
            $bootScripts,
        );
        $system = $project->system();
        $dir = $system->projectDirPath($project->username()) . '/entrypoint-init.d';
        $system->runProcess("sudo rm {$dir}/*.sh");
        foreach ($scriptFiles as $name => $script) {
            $file = "{$dir}/{$name}";
            $system->filesystem()->filePutContents($file, $script);
        }
    }

    private function setupCrontabFile(PhpHosting $project): void
    {
        $system = $project->system();
        $file = $system->projectDirPath($project->username()) . '/crontabs/www-data';
        if (!is_file($file)) {
            $system->exec(['sudo', 'touch', $file]);
        }
        $chown = $project->userModel()->getChownString() ?? '33:33';
        $system->exec(['sudo', 'chown', $chown, $file]);
        $system->exec(['sudo', 'chmod', '600', $file]);
    }
}
