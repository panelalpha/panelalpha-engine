<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Dind\RegistryAuth;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * The project's `registry-auth` logins, in a randomly named Docker config dir only while a deploy step runs (~/.docker
 * is untouched); never fails a deploy: a login that cannot be written leaves the pull anonymous, so the 401 is the error.
 */
final class RegistryLogin
{
    private int $depth = 0;

    private ?string $configDir = null;

    private ?RegistryAuth $auth = null;

    public function __construct(
        private DindProject $project,
    ) {}

    public function auth(): RegistryAuth
    {
        return $this->auth ??= RegistryAuth::fromStored($this->project->userModel()->getRegistryAuth());
    }

    /** Whether $image comes from a registry this project has a login for. */
    public function covers(string $image): bool
    {
        return $this->auth()->covers($image);
    }

    /** The Docker client config directory while a deploy step holds the logins, else null. */
    public function configDir(): ?string
    {
        return $this->configDir;
    }

    /**
     * Run $work with the logins written; nested calls share one directory.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function during(callable $work): mixed
    {
        if ($this->depth === 0) {
            $this->auth = null;
            $this->open();
        }
        $this->depth++;
        try {
            return $work();
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                $this->close();
            }
        }
    }

    private function open(): void
    {
        $auth = $this->auth();
        if ($auth->isEmpty()) {
            return;
        }

        $this->project->shell()->logger()?->mask($auth->secrets());
        $user = $this->project->userModel();
        $chown = $user->getChownString() ?: $this->project->username();
        $dir = rtrim($this->project->homeDirPath(), '/').'/.panelalpha-registry-'.bin2hex(random_bytes(8));
        if (! $this->writeLogins($auth, $dir, $chown)) {
            return;
        }

        $this->configDir = $dir;
        $this->project->shell()->logger()?->info('Using registry logins for: '.implode(', ', $auth->hosts()));
    }

    private function writeLogins(RegistryAuth $auth, string $dir, string $chown): bool
    {
        try {
            $system = $this->project->system();
            $system->filesystem()->makeDirWithParents($dir, $chown);
            $system->exec(Privileged::accountFiles(...$this->project->userModel()->linuxIds())->changeMode('700', $dir));
            $system->filesystem()->writeFileReplacingPath($dir.'/config.json', $auth->dockerConfigJson(), $chown, '600');
        } catch (\Throwable $e) {
            Log::warning("Could not write registry logins for {$this->project->username()}: ".$e->getMessage());
            $this->project->shell()->logger()?->warn('Could not use the registry-auth logins; pulling anonymously');
            $this->remove($dir);

            return false;
        }

        return true;
    }

    private function close(): void
    {
        if ($this->configDir !== null) {
            $this->remove($this->configDir);
            $this->configDir = null;
        }
    }

    private function remove(string $dir): void
    {
        try {
            $this->project->system()->exec(Privileged::files()->removeTree($dir));
        } catch (\Throwable $e) {
            Log::warning("Could not remove registry logins {$dir}: ".$e->getMessage());
        }
    }
}
