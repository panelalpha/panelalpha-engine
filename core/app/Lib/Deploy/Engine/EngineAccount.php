<?php

namespace App\Lib\Deploy\Engine;

use App\Lib\Deploy\SafeName;

/**
 * Which hosting account a {@see ContainerEngine} call is about.
 *
 * Only the facts every engine needs — who the account is, where its files are,
 * and what identity its builds run as. Anything engine-specific it holds
 * itself. No Laravel dependencies.
 */
final class EngineAccount
{
    /**
     * @param  string  $username  the hosting account, also its Linux user
     * @param  string  $homeDir  absolute path of the account's home on the host
     * @param  string  $identity  "uid:gid" builds and bind-mounted output run as
     * @param  string|null  $controlFile  host-side handle for this account's
     *                                    engine. DinD: the account container's compose file.
     */
    public function __construct(
        public readonly string $username,
        public readonly string $homeDir,
        public readonly string $identity = '33:33',
        public readonly ?string $controlFile = null,
    ) {
        SafeName::assert($username, 'account username');
        $home = rtrim($homeDir, '/');
        if (! preg_match('#^(/[a-zA-Z0-9_.-]+)+$#', $home) || str_contains($home, '/../') || str_ends_with($home, '/..')) {
            throw new \InvalidArgumentException('Invalid account home directory');
        }
        if (preg_match('/^\d+:\d+$/', $identity) !== 1) {
            throw new \InvalidArgumentException('Invalid build user identity');
        }
    }

    /**
     * Where the account's application sources live — the directory a deploy
     * clones into, builds from, and bind-mounts into build containers.
     */
    public function projectDir(): string
    {
        return rtrim($this->homeDir, '/').'/project';
    }

    /**
     * @throws \LogicException when an engine that needs a control file was
     *                         handed an account without one.
     */
    public function controlFileOrFail(): string
    {
        if ($this->controlFile === null || $this->controlFile === '') {
            throw new \LogicException(
                "Account '{$this->username}' has no engine control file; "
                    .'this engine cannot be reached without one.'
            );
        }

        return $this->controlFile;
    }
}
