<?php

namespace App\System\Services;

use App\Exceptions\DockerErrorException;
use App\System as EngineSystem;
use App\System\Privileged;
use App\System\Privileged\RootEngineServices;

class PureFtpd
{
    /** Carries the password from core to the ftp container's shell, never on a command line. */
    public const PASSWORD_ENV = 'PA_FTP_PASSWORD';

    public function __construct(
        private EngineSystem $system,
    ) {}

    /**
     * @throws DockerErrorException
     */
    public function reload(): void
    {
        $this->system->exec($this->services()->ftpTouchPasswordFile());
        $this->makeDatabase();
    }

    /**
     * @throws DockerErrorException
     */
    public function makeDatabase(): void
    {
        $this->system->exec($this->services()->ftpMakeDatabase());
    }

    public function userExists(string $username): bool
    {
        $process = $this->system->runProcess($this->services()->ftpShowUser($username));

        if ($process->getExitCode() == 0) {
            return true;
        }

        if ($process->getExitCode() == 16) {
            return false;
        }

        throw new \Exception($process->getErrorOutput());
    }

    /**
     * @throws DockerErrorException
     */
    public function useradd(
        string $ftpUser,
        string $password,
        int $uid,
        int $gid,
        string $containerPath,
        ?int $quota = null,
    ): void {
        $enforcedQuota = $this->enforcedQuota($quota);
        $this->withPassword($password, $this->services()->ftpAddUser(
            $ftpUser, $uid, $gid, $containerPath, $enforcedQuota === null ? '' : (string) $enforcedQuota,
        ));
        $this->makeDatabase();
    }

    /**
     * @throws DockerErrorException
     */
    public function passwd(string $ftpUser, string $password): void
    {
        $this->withPassword($password, $this->services()->ftpSetPassword($ftpUser));
    }

    /**
     * An empty value clears the quota; omitting the option keeps the old one.
     *
     * @throws DockerErrorException
     */
    public function usermodQuota(string $ftpUser, ?int $quota): void
    {
        $enforcedQuota = $this->enforcedQuota($quota);
        $this->system->exec($this->services()->ftpSetQuota($ftpUser, $enforcedQuota === null ? '' : (string) $enforcedQuota));
    }

    /**
     * @throws DockerErrorException
     */
    public function deleteUser(string $username): void
    {
        $this->system->exec($this->services()->ftpDeleteUser($username));
    }

    /** Only a positive quota is set: 0 and unlimited (any negative) set none. */
    private function enforcedQuota(?int $quota): ?int
    {
        return $quota !== null && $quota > 0 ? $quota : null;
    }

    /**
     * pure-pw reads the password twice from stdin. It travels in the environment and a builtin printf, never in an argv.
     *
     * @param  list<string>  $argv
     *
     * @throws DockerErrorException
     */
    private function withPassword(string $password, array $argv): void
    {
        $this->system->exec($argv, [self::PASSWORD_ENV => $password]);
    }

    private function services(): RootEngineServices
    {
        return Privileged::engineServices($this->system->composeFilePath());
    }
}
