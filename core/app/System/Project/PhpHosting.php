<?php

namespace App\System\Project;

use App\Lib\Deploy\Dind\TenantNetwork;
use App\Models\Domain as DomainModel;
use App\Models\User as ModelsUser;
use App\System;
use App\System\Project as ProjectAggregate;
use App\System\Project\PhpHosting\EnvironmentSetup;
use App\System\Project\PhpHosting\FpmApacheStack;
use App\System\Project\PhpHosting\PhpRuntime;
use App\System\Project\PhpHosting\PhpStack;
use App\System\Project\PhpHosting\PhpStackResolver;
use App\System\Project\PhpHosting\Services\RunnerServiceManager;
use App\System\Project\PhpHosting\Services\S6ServiceManager;
use App\System\Project\PhpHosting\Services\ServiceManager;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * PHP hosting Runtime (classic stack).
 */
class PhpHosting implements Runtime
{
    private ?PhpStack $stackAdapter = null;
    private ?PhpRuntime $phpRuntime = null;

    public function __construct(
        private readonly ProjectAggregate $project,
    ) {
    }

    public function kind(): string
    {
        return 'php-hosting';
    }

    public function project(): ProjectAggregate
    {
        return $this->project;
    }

    public function system(): System
    {
        return $this->project->system();
    }

    public function username(): string
    {
        return $this->project->username();
    }

    public function userModel(): ModelsUser
    {
        return $this->requireUserModel();
    }

    public function composeFilePath(): string
    {
        return $this->project->projectDirPath() . '/docker-compose.yml';
    }

    public function exists(): bool
    {
        return file_exists($this->composeFilePath());
    }

    public function defaultServiceName(): string
    {
        return 'php';
    }

    /**
     * Project-wide PHP handler / ini / WP-CLI — not a customer Application.
     */
    public function phpRuntime(): PhpRuntime
    {
        return $this->phpRuntime ??= new PhpRuntime($this);
    }

    /**
     * @internal PHP hosting stack adapters
     */
    public function stackVariant(): PhpStack
    {
        return $this->stack();
    }

    public function materialize(): void
    {
        $this->createFromTemplate();
        $this->buildIfMissing();
    }

    public function createFromTemplate(): void
    {
        (new EnvironmentSetup())->createFromTemplate($this, $this->stack());
    }

    public function start(): void
    {
        $this->prepareTenantNetwork();
        $this->system()->exec("sudo docker compose -f {$this->composeFilePath()} up -d --remove-orphans");
        // The account's bridge port carries nothing until it is bound (engine#529).
        $this->prepareTenantNetwork();
    }

    /**
     * The account runs on pash-tenants (engine#217), which compose cannot start
     * without and whose firewall a reboot drops. A failure is a warning: with no
     * rules, enable_icc still keeps the network's members apart.
     */
    private function prepareTenantNetwork(): void
    {
        try {
            $this->system()->exec(TenantNetwork::firewallArgv(), [], 60);
        } catch (\Exception $e) {
            Log::warning("Tenant network firewall not applied for {$this->username()}: " . trim($e->getMessage()));
        }
    }

    public function stop(): void
    {
        $this->system()->exec("sudo docker compose -f {$this->composeFilePath()} down");
    }

    public function remove(): void
    {
        if ($this->exists()) {
            $this->system()->runProcess(
                "sudo docker compose -f {$this->composeFilePath()} down -v --remove-orphans"
            );
        }
    }

    public function awaitReady(int $tries = 12, int $intervalSeconds = 5): void
    {
        $this->stack()->waitForAllRunning($this, $tries, $intervalSeconds);
    }

    public function isRunning(): bool
    {
        $command = "sudo docker compose -f {$this->composeFilePath()} ps --services --filter status=running";
        $result = trim($this->system()->exec($command));

        return $result !== '';
    }

    public function build(): void
    {
        $this->system()->exec("sudo docker compose -f {$this->composeFilePath()} build");
    }

    public function buildIfMissing(): void
    {
        $image = null;
        try {
            /** @var mixed */
            $parsed = Yaml::parseFile($this->composeFilePath());
            if (
                is_array($parsed)
                && is_array($parsed['services'])
                && is_array($parsed['services']['php'])
                && is_string($parsed['services']['php']['image'])
            ) {
                $image = $parsed['services']['php']['image'];
            }
        } catch (\Exception $e) {
        }
        $imageEscaped = escapeshellarg($image);
        $pathEscaped = escapeshellarg($this->composeFilePath());
        if ($image) {
            $this->system()->exec(
                "sudo docker image inspect {$imageEscaped} >/dev/null 2>&1"
                    . " || sudo docker compose -f {$pathEscaped} pull"
                    . " || sudo docker compose -f {$pathEscaped} build"
            );

            return;
        }

        $this->build();
    }

    /**
     * Apache-in-container domain mechanics (nginx-proxy stack only).
     */
    public function fpmApache(): ?FpmApacheStack
    {
        $stack = $this->stack();

        return $stack instanceof FpmApacheStack ? $stack : null;
    }

    public function createDomainConfig(DomainModel $domain): void
    {
        $this->fpmApache()?->createDomainConfig($this, $domain);
    }

    public function reloadWebserver(): void
    {
        $this->fpmApache()?->reloadWebserver($this);
    }

    public function rebuildDomain(DomainModel $domain): void
    {
        $apache = $this->fpmApache();
        if ($apache === null) {
            return;
        }

        $apache->rebuildDomain($this, $domain);
    }

    public function deleteDomainConfig(string $domainName): void
    {
        $this->fpmApache()?->deleteDomainConfig($this, $domainName);
    }

    /**
     * What keeps the account's processes up: s6, or the entrypoint runner on
     * an account rendered before s6. Not cached: rendering the template changes it.
     */
    public function services(): ServiceManager
    {
        return S6ServiceManager::manages($this) ? new S6ServiceManager($this) : new RunnerServiceManager($this);
    }

    public function reloadCron(): void
    {
        $this->services()->reload('cron');
    }

    public function runEntrypointInitScripts(): void
    {
        $this->system()->runProcess($this->execArgv([
            'bash', '-c', 'for f in /entrypoint-init.d/*.sh; do [ -f "$f" ] && bash "$f"; done; true',
        ]));
    }

    public function syncServices(): void
    {
        $this->services()->sync();
    }

    /**
     * Run $command in the account's php service.
     *
     * @param list<string> $command
     *
     * @return list<string>
     */
    public function execArgv(array $command): array
    {
        return ['sudo', 'docker', 'compose', '-f', $this->composeFilePath(), 'exec', '-T', $this->defaultServiceName(), ...$command];
    }

    public function reloadApache(): void
    {
        $this->fpmApache()?->reloadApache($this);
    }

    public function enableApacheMod(string $mod): void
    {
        $this->fpmApache()?->enableApacheMod($this, $mod);
    }

    public function disableApacheMod(string $mod): void
    {
        $this->fpmApache()?->disableApacheMod($this, $mod);
    }

    public function rebuildDomains(): void
    {
        $this->fpmApache()?->rebuildDomains($this);
    }

    public function deleteAllDomainsConfigs(): void
    {
        $this->fpmApache()?->deleteAllDomainsConfigs($this);
    }

    protected function requireUserModel(): ModelsUser
    {
        return $this->project->model();
    }

    private function stack(): PhpStack
    {
        return $this->stackAdapter ??= PhpStackResolver::resolve(
            $this->system(),
            $this->requireUserModel(),
            $this,
        );
    }
}
