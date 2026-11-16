<?php

namespace App\System\Privileged;

/** Commands in the engine's shared services, one method each, since every account depends on those containers. */
final class RootEngineServices
{
    public const LITESPEED_PASSWORD = 'PA_LITESPEED_ADMIN_PASSWORD';

    private const AREA = 'compose';

    private const LITESPEED_VERBS = ['restart', 'fullrestart', 'condrestart'];

    private const FTP_PASSWORD = 'PA_FTP_PASSWORD';

    public function __construct(private readonly string $file) {}

    /** @return list<string> */
    public function runningServices(): array
    {
        return $this->root('engineRunningServices', []);
    }

    /** @return list<string> */
    public function nginxConfigTest(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function apacheConfigTest(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function openTcpSockets(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function nginxReload(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function modsecurityNginxCheck(string $testDir): array
    {
        return $this->root(__FUNCTION__, [Arguments::path($testDir)]);
    }

    /** @return list<string> */
    public function modsecurityApacheCheck(string $testDir): array
    {
        return $this->root(__FUNCTION__, [Arguments::path($testDir)]);
    }

    /** @return list<string> */
    public function apacheGraceful(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function apacheRestart(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /**
     * `lswsctrl restart`, `fullrestart` or `condrestart`.
     *
     * @return list<string>
     */
    public function litespeedControl(string $verb): array
    {
        if (! in_array($verb, self::LITESPEED_VERBS, true)) {
            throw InvalidArgument::of('lswsctrl command', $verb);
        }

        return $this->root(__FUNCTION__, [$verb]);
    }

    /** @return list<string> */
    public function litespeedVersion(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function litespeedConfigTest(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /**
     * The password comes from PA_LITESPEED_ADMIN_PASSWORD in the caller's environment.
     *
     * @return list<string>
     */
    public function litespeedResetAdminPassword(): array
    {
        return Root::action(self::AREA, __FUNCTION__, [Arguments::path($this->file)], passing: [self::LITESPEED_PASSWORD]);
    }

    /** @return list<string> */
    public function eximUpdateConfig(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /**
     * Also stops a daemon the init script missed.
     *
     * @return list<string>
     */
    public function eximRestart(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /**
     * The broker refuses an address starting with `-`, which exim would read as an option.
     *
     * @return list<string>
     */
    public function eximSendTestEmail(string $email): array
    {
        return $this->root(__FUNCTION__, [$email]);
    }

    /** @return list<string> */
    public function sftpSyncLogins(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /**
     * Prints how many sessions were ended.
     *
     * @param  array<int, string>  $logins
     * @return list<string>
     */
    public function sftpKillSessions(array $logins): array
    {
        return $this->root(__FUNCTION__, array_values($logins));
    }

    /** @return list<string> */
    public function ftpTouchPasswordFile(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function ftpMakeDatabase(): array
    {
        return $this->root(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function ftpShowUser(string $user): array
    {
        return $this->root(__FUNCTION__, [$user]);
    }

    /**
     * An empty $quota clears it.
     *
     * @return list<string>
     */
    public function ftpSetQuota(string $user, string $quota): array
    {
        return $this->root(__FUNCTION__, [$user, $quota]);
    }

    /** @return list<string> */
    public function ftpDeleteUser(string $user): array
    {
        return $this->root(__FUNCTION__, [$user]);
    }

    /**
     * The password comes from PA_FTP_PASSWORD in the caller's environment. An empty $quota sets none.
     *
     * @return list<string>
     */
    public function ftpAddUser(string $user, int $uid, int $gid, string $directory, string $quota): array
    {
        if ($uid < 1 || $gid < 1) {
            throw InvalidArgument::of('FTP account', $uid.':'.$gid);
        }

        return $this->withPassword(__FUNCTION__, [$user, (string) $uid, (string) $gid, $directory, $quota]);
    }

    /**
     * The password comes from PA_FTP_PASSWORD in the caller's environment.
     *
     * @return list<string>
     */
    public function ftpSetPassword(string $user): array
    {
        return $this->withPassword(__FUNCTION__, [$user]);
    }

    /**
     * Writes `/data/<md5 of $url>-desktop|mobile.json`. Chrome resolves only by $resolverRules.
     *
     * @return list<string>
     */
    public function lighthouseAudit(string $url, bool $desktop, string $resolverRules): array
    {
        return $this->root(__FUNCTION__, [$url, $desktop ? 'desktop' : '', $resolverRules]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function withPassword(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, [Arguments::path($this->file), ...$values], passing: [self::FTP_PASSWORD]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function root(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, [Arguments::path($this->file), ...$values]);
    }
}
