<?php

namespace App\System\Project\PhpHosting;

use App\System\Project\PhpHosting;

final class EnvironmentSetup
{
    public function createFromTemplate(PhpHosting $project, PhpStack $stack): void
    {
        $system = $project->system();
        $user = $project->userModel();
        $projectDir = $system->projectDirPath($project->username());

        $blockDevice = '';
        $deviceReadBps = $user->getDeviceReadBps();
        $deviceWriteBps = $user->getDeviceWriteBps();
        if ($deviceReadBps !== null || $deviceWriteBps !== null) {
            $blockDevice = $system->filesystem()->getHomeFilesystemParentBlockDevice();
        }

        $templateVars = [
            'user' => $user->username,
            'uid' => $user->getUid() ?? 33,
            'gid' => $user->getGid() ?? 33,
            'cpu_limit' => (string) $user->getCpuLimit(),
            'memory_limit' => (string) $user->effectiveMemoryLimit(),
            'device_read_bps' => $deviceReadBps,
            'device_write_bps' => $deviceWriteBps,
            'block_device' => $blockDevice,
            'php_versions' => $system->php()->listAvailablePhpVersions(),
        ];
        $templatesDir = $system->projectFilesTemplateDirPath($user->getTemplate());
        $exclude = ['crontabs/www-data'];
        $system->filesystem()->makeDirFromTemplate($projectDir, $templatesDir, $templateVars, null, null, $exclude);

        $dockerfile = $stack->dockerfileTemplateName();
        $dockercompose = $stack->composeTemplateName();
        $system->exec("sudo cp {$projectDir}/{$dockerfile} {$projectDir}/Dockerfile");
        $system->exec("sudo cp {$projectDir}/{$dockercompose} {$projectDir}/docker-compose.yml");

        $stack->applySettings($project);
        $manager = $project->services();
        $services = $stack->services($project);
        $this->setupEntrypointInitScripts($project, $stack, $manager->bootScripts($services));
        $manager->write($services);

        $system->exec("sudo chown -R www-data:www-data {$projectDir}");

        $this->setupCrontabFile($project);
    }

    public function applyRedisSettings(PhpHosting $project): void
    {
        $system = $project->system();
        $user = $project->userModel();
        $settings = $user->getRedisConfig();
        $projectDir = $system->projectDirPath($project->username());
        $confFile = "{$projectDir}/redis/conf.d/custom.conf";

        $lines = [];
        foreach ($settings as $key => $value) {
            $lines[] = "{$key} {$value}";
        }
        $conf = implode("\n", $lines) . "\n";

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
        $uid = $userModel->getUid() ?? 33;
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
     * @param array<string, string> $bootScripts
     */
    private function setupEntrypointInitScripts(PhpHosting $project, PhpStack $stack, array $bootScripts): void
    {
        $scriptFiles = array_merge(
            $this->baseEntrypointInitScripts($project),
            $stack->entrypointInitScripts($project),
            $bootScripts,
        );
        $dir = $project->system()->projectDirPath($project->username()) . '/entrypoint-init.d';
        $system = $project->system();
        $system->runProcess("sudo rm {$dir}/*.sh");
        foreach ($scriptFiles as $name => $script) {
            $file = "{$dir}/{$name}";
            $system->filesystem()->filePutContents($file, $script);
        }
    }

    private function setupCrontabFile(PhpHosting $project): void
    {
        $projectDir = $project->system()->projectDirPath($project->username());
        $file = "{$projectDir}/crontabs/www-data";
        if (!is_file($file)) {
            $project->system()->exec(['sudo', 'touch', $file]);
        }
        $user = $project->userModel();
        $chown = $user->getChownString() ?? '33:33';
        $project->system()->exec(['sudo', 'chown', $chown, $file]);
        $project->system()->exec(['sudo', 'chmod', '600', $file]);
    }
}
