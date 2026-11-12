<?php

namespace App\System\Privileged;

/** The host's accounts behind the projects, in the host's namespaces. */
final class RootUsers
{
    private const AREA = 'users';

    /**
     * No home is made. The engine makes it and keeps it root-owned.
     *
     * @return list<string>
     */
    public function create(string $username, string $home): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::username($username), Arguments::path($home)]);
    }

    /** @return list<string> */
    public function delete(string $username): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::username($username)]);
    }

    /** @return list<string> */
    public function uid(string $username): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::username($username)]);
    }

    /** @return list<string> */
    public function gid(string $username): array
    {
        return $this->onHost(__FUNCTION__, [Arguments::username($username)]);
    }

    /**
     * Soft and hard limits alike, blocks in KiB. 0 is no limit.
     *
     * @return list<string>
     */
    public function setQuota(string $username, int $blocks, int $inodes, string $mountPoint): array
    {
        return $this->onHost(__FUNCTION__, [
            Arguments::username($username),
            HostValues::count($blocks, 'block limit'),
            HostValues::count($inodes, 'inode limit'),
            Arguments::path($mountPoint),
        ]);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function onHost(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values, true);
    }
}
