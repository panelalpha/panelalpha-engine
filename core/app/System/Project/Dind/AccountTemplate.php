<?php

namespace App\System\Project\Dind;

use App\Integrations\Tunnels\Cloudflare;
use App\Lib\Deploy\Compose\ServiceHardener;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Platform\ProjectContext;
use App\Models\Setting;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project\ContainerLimits;
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
    ) {}

    public function create(): void
    {
        $model = $this->project->userModel();
        $system = $this->project->system();

        $this->renderTemplate($model, $system, $this->project->projectDirPath());
        $this->project->system()->project($model)->cron()->ensureCrontabFile($model->getChownString());
        $this->renderStateTheTemplateDropped($model);
        $this->storeWelcomeAppPort($model);
    }

    private function renderTemplate(ModelsUser $model, System $system, string $projectDir): void
    {
        $projectTemplateDir = $system->projectFilesTemplateDirPath('dind');
        $templateVars = $this->templateVars($model, $system);
        $system->filesystem()->makeDirFromTemplate($projectDir, $projectTemplateDir, $templateVars);
        $this->project->setupEntrypointInitScripts();
        $this->project->setupDaemonJson();
        $system->runProcess(
            "sudo test -f {$projectDir}/entrypoint.sh && sudo chmod +x {$projectDir}/entrypoint.sh"
        );
    }

    /**
     * The tunnel connector rendered from the account's state, not off, so a live tunnel keeps its
     * connector; and nothing kept of the init a re-rendered account ran before.
     */
    private function renderStateTheTemplateDropped(ModelsUser $model): void
    {
        Cloudflare::renderConnector($model, Cloudflare::connectorWanted($model));
        SupervisordServiceManager::removeLayout($this->project);
    }

    private function storeWelcomeAppPort(ModelsUser $model): void
    {
        $composePath = $this->bootstrapWelcomeApp($model);
        $portDetection = DetectAppPort::detectAllPorts($composePath);
        $model->setAppPort($portDetection['primary'] ?? 8080);
        $model->save();
    }

    /**
     * @param  bool  $apiAccess  whether the project may use the Engine API, which
     *                           its own egress guard has to know about
     * @return array<string, string>
     */
    public function entrypointInitScripts(bool $apiAccess = false): array
    {
        $userModel = $this->project->userModel();
        $username = $userModel->username;
        [$uid] = $userModel->linuxIds();
        $dockerConfig = ShellOperations::DOCKER_CONFIG_DIR;
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
# daemon.json itself is rendered on the host and bind-mounted read-only now;
# nothing in this container writes it any more.
mkdir -p "/home/{$username}/.docker"
chown {$uid}:{$uid} "/home/{$username}/.docker"
echo "[init] created /home/{$username}/.docker"
mkdir -p -m 700 "/home/{$username}/{$dockerConfig}"
BASH;

        $script .= $this->cgroupNestingScript();

        $scripts = ['useradd.sh' => $script];
        if (TenantEgressGuard::enabled()) {
            $scripts[TenantEgressGuard::FILE] = TenantEgressGuard::script($this->hostIpv4Addresses(), $apiAccess);
        }

        return $scripts;
    }

    /**
     * The account's `/etc/docker/daemon.json`, rendered on the host and bind-mounted read-only, so trusting
     * a registry needs no shell inside the account. Logs rotate, or they grow inside the account's quota.
     */
    public function daemonJson(): string
    {
        $username = $this->project->userModel()->username;

        return json_encode([
            'data-root' => "/home/{$username}/docker",
            'live-restore' => true,
            'ip' => '0.0.0.0',
            'ipv6' => false,
            'group' => $username,
            'insecure-registries' => [DindImageStore::CACHE_REGISTRY, DindImageStore::PROXY_REGISTRY],
            'registry-mirrors' => ['http://'.DindImageStore::PROXY_REGISTRY],
            'log-driver' => 'json-file',
            'log-opts' => ServiceHardener::LOG_OPTIONS,
        ], JSON_UNESCAPED_SLASHES);
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
            $public = Setting::get('default_ipv4');
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

        if ($paths->existingComposeFile() === null && ! $model->hasGitProject() && ! $hasSources) {
            (new WelcomeBootstrap($this->project, $paths))->writeIfNeeded();
        }

        return $paths->composeFileForPorts();
    }

    /**
     * @return array<string, mixed>
     */
    private function templateVars(ModelsUser $user, System $system): array
    {
        [$uid, $gid] = $user->linuxIds();

        return [
            'isolation' => AccountRuntime::composeIsolation(),
            'user' => $user->username,
            'uid' => $uid,
            'gid' => $gid,
            ...ContainerLimits::templateVars($user, $system),
            'php_versions' => $system->php()->listAvailablePhpVersions(),
            'proc_mounts' => LxcfsProc::mounts(LxcfsProc::present($system), AccountRuntime::isSysbox()),
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
