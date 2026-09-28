<?php

namespace App\System\Project\Dind;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Dind\DindEngine;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Services\SupervisordServiceManager;

/**
 * Outer DinD account container from the dind project template.
 */
final class AccountTemplate
{
    private ?Paths $paths = null;

    public function __construct(
        private DindProject $project,
    ) {
    }

    public function create(): void
    {
        $model = $this->project->userModel();
        $system = $this->project->system();
        $projectDir = $this->project->projectDirPath();

        $projectTemplateDir = $system->projectFilesTemplateDirPath('dind');
        $templateVars = $this->templateVars($model, $system);
        $system->filesystem()->makeDirFromTemplate($projectDir, $projectTemplateDir, $templateVars);
        $this->project->setupEntrypointInitScripts();
        $system->runProcess(
            "sudo test -f {$projectDir}/entrypoint.sh && sudo chmod +x {$projectDir}/entrypoint.sh"
        );
        $this->project->system()->project($model)->cron()->ensureCrontabFile($model->getChownString());
        // Rendered from the account's state, not off: a rebuild re-renders the
        // template and used to leave a live tunnel with no connector.
        Cloudflare::renderConnector($model, Cloudflare::connectorWanted($model));
        // The template is s6's; a re-rendered account keeps nothing of supervisord.
        SupervisordServiceManager::removeLayout($this->project);

        $composePath = $this->bootstrapWelcomeApp($model);
        $portDetection = DetectAppPort::detectAllPorts($composePath);
        $model->setAppPort($portDetection['primary'] ?? 8080);
        $model->save();
    }

    /**
     * @return array<string, string>
     */
    public function entrypointInitScripts(): array
    {
        $userModel = $this->project->userModel();
        $username = $userModel->username;
        $uid = $userModel->getUid() ?? 33;
        $script = <<<BASH
# /run is a tmpfs (see the account's compose), so it starts empty on every
# boot. Debian expects the directories its packages declare to be there —
# /run/php owned by www-data, /run/lock, /run/dbus — and nothing else in this
# container recreates them, because there is no systemd to run tmpfiles at
# boot. Cheap and idempotent, so it runs unconditionally.
systemd-tmpfiles --create >/dev/null 2>&1 || true
echo "[init] repopulated /run from tmpfiles.d"
if getent passwd {$uid} >/dev/null 2>&1 || getent passwd {$username} >/dev/null 2>&1; then
  echo "[init] passwd entry already exists for UID={$uid} or user={$username}, skipping"
else
  echo "[init] adding /etc/passwd entry for UID={$uid}"
  useradd --uid {$uid} --home-dir /home/{$username} --shell /bin/bash {$username}
fi
mkdir -p "/home/\$(hostname)/docker"
cat > /etc/docker/daemon.json <<EOF
{"data-root": "/home/\$(hostname)/docker", "ip": "0.0.0.0", "ipv6": false, "group": "{$username}", "insecure-registries": ["panelalpha-cache-registry:5000", "panelalpha-registry-proxy:5000"], "registry-mirrors": ["http://panelalpha-registry-proxy:5000"]}
EOF
echo "[init] generated /etc/docker/daemon.json"
mkdir -p "/home/{$username}/.docker"
chown {$uid}:{$uid} "/home/{$username}/.docker"
echo "[init] created /home/{$username}/.docker"
BASH;

        $script .= $this->cgroupNestingScript();

        $scripts = ['useradd.sh' => $script];
        if (TenantEgressGuard::enabled()) {
            $scripts[TenantEgressGuard::FILE] = TenantEgressGuard::script($this->hostIpv4Addresses());
        }

        return $scripts;
    }

    /**
     * Every address of the host, for the egress guard to refuse all but mail
     * and the sites on. Best-effort: the guard adds the gateway and
     * host.docker.internal itself, so an empty list still covers those.
     *
     * @return list<string>
     */
    private function hostIpv4Addresses(): array
    {
        try {
            $ips = $this->project->system()->network()->localIpv4Addresses();
        } catch (\Throwable) {
            $ips = [];
        }
        try {
            $public = \App\Models\Setting::get('default_ipv4');
            if (is_string($public) && $public !== '') {
                $ips[] = $public;
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($ips));
    }

    private function bootstrapWelcomeApp(ModelsUser $model): string
    {
        $paths = $this->paths();
        $appDir = $paths->appDir();
        $hasSources = ProjectContext::listRootFiles($appDir) !== [];

        if ($paths->existingComposeFile() === null && !$model->hasGitProject() && !$hasSources) {
            (new WelcomeBootstrap($this->project, $paths))->writeIfNeeded();
        }

        return $paths->composeFileForPorts();
    }

    /**
     * @return array<string, mixed>
     */
    private function templateVars(ModelsUser $user, System $system): array
    {
        $blockDevice = '';
        $deviceReadBps = $user->getDeviceReadBps();
        $deviceWriteBps = $user->getDeviceWriteBps();
        if ($deviceReadBps !== null || $deviceWriteBps !== null) {
            $blockDevice = $system->filesystem()->getHomeFilesystemParentBlockDevice();
        }

        return [
            'isolation' => AccountRuntime::composeIsolation(),
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
    }

    private function cgroupNestingScript(): string
    {
        if (AccountRuntime::isSysbox()) {
            return '';
        }

        return <<<'BASH'

# cgroup v2 nesting — privileged accounts only.
if [ -f /sys/fs/cgroup/cgroup.controllers ] && [ -w /sys/fs/cgroup ]; then
  if mkdir -p /sys/fs/cgroup/init 2>/dev/null; then
    xargs -rn1 </sys/fs/cgroup/cgroup.procs >/sys/fs/cgroup/init/cgroup.procs 2>/dev/null || true
    sed -e 's/ / +/g' -e 's/^/+/' </sys/fs/cgroup/cgroup.controllers \
      >/sys/fs/cgroup/cgroup.subtree_control 2>/dev/null || true
    echo "[init] delegated cgroup2 controllers to children"
  fi
fi
BASH;
    }

    private function paths(): Paths
    {
        return $this->paths ??= new Paths($this->project);
    }
}
