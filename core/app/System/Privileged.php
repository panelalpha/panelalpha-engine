<?php

namespace App\System;

use App\System\Privileged\AccountCommands;
use App\System\Privileged\AccountFileManager;
use App\System\Privileged\AccountFiles;
use App\System\Privileged\BrokerRefusal;
use App\System\Privileged\HostNamespaces;
use App\System\Privileged\RootAccountCompose;
use App\System\Privileged\RootAccountContainer;
use App\System\Privileged\RootAccountDocker;
use App\System\Privileged\RootAccountDockerLines;
use App\System\Privileged\RootBackupStore;
use App\System\Privileged\RootBuildCache;
use App\System\Privileged\RootCompose;
use App\System\Privileged\RootComposeLines;
use App\System\Privileged\RootDocker;
use App\System\Privileged\RootDockerLines;
use App\System\Privileged\RootEngineCompose;
use App\System\Privileged\RootEngineComposeLines;
use App\System\Privileged\RootEngineServices;
use App\System\Privileged\RootFileLines;
use App\System\Privileged\RootFiles;
use App\System\Privileged\RootFirewall;
use App\System\Privileged\RootHost;
use App\System\Privileged\RootNetwork;
use App\System\Privileged\RootProbes;
use App\System\Privileged\RootScripts;
use App\System\Privileged\RootUsers;
use App\System\Privileged\RootWebserver;

/** Every root command, each a named method, so a caller can ask for `docker rmi` and nothing else. */
final class Privileged
{
    /** The exit code of a request the broker refused, protocol.EXIT_REFUSED. */
    public const REFUSED = 77;

    /** The broker could not enter the host's namespaces, so the command never ran there. */
    public const HOST_NAMESPACES_UNAVAILABLE = "~cannot enter the host's namespaces~";

    /** Whether $output is the broker failing to reach the host, rather than the host's answer. */
    public static function hostNamespacesUnavailable(string $output): bool
    {
        return preg_match(self::HOST_NAMESPACES_UNAVAILABLE, $output) === 1;
    }

    /** What the broker said about a request it refused, without its client's own prefix. */
    public static function refusalReason(string $said): string
    {
        return BrokerRefusal::reasonIn($said);
    }

    public static function files(): RootFiles
    {
        return new RootFiles;
    }

    /** File commands in the host's namespaces. */
    public static function hostFiles(): RootFiles
    {
        return new RootFiles(inHostNamespaces: true);
    }

    /** File commands as shell lines. */
    public static function fileLines(): RootFileLines
    {
        return new RootFileLines;
    }

    public static function docker(): RootDocker
    {
        return new RootDocker;
    }

    /** Docker commands as shell lines. */
    public static function dockerLines(): RootDockerLines
    {
        return new RootDockerLines;
    }

    /** `docker compose` or `docker buildx build` for $username's stack, run by the broker against its own daemon. */
    public static function accountCompose(string $username): RootAccountCompose
    {
        return new RootAccountCompose($username);
    }

    /** A command in $username's own DinD container, through the host daemon's Engine API. */
    public static function accountContainer(string $username): RootAccountContainer
    {
        return new RootAccountContainer($username);
    }

    /** $username's own dockerd, through the Engine API. */
    public static function accountDocker(string $username): RootAccountDocker
    {
        return new RootAccountDocker($username);
    }

    /** $username's own dockerd as shell lines. */
    public static function accountDockerLines(string $username): RootAccountDockerLines
    {
        return new RootAccountDockerLines($username);
    }

    public static function compose(string $file): RootCompose
    {
        return new RootCompose($file);
    }

    /** Compose commands as shell lines, with the file quoted here. */
    public static function composeLines(string $file): RootComposeLines
    {
        return new RootComposeLines($file);
    }

    /** Commands in the engine's shared services. */
    public static function engineServices(string $composeFile): RootEngineServices
    {
        return new RootEngineServices($composeFile);
    }

    public static function engineCompose(string $projectDirectory): RootEngineCompose
    {
        return new RootEngineCompose($projectDirectory);
    }

    /** sites-db commands as shell lines, every value quoted. */
    public static function engineComposeLines(string $projectDirectory): RootEngineComposeLines
    {
        return new RootEngineComposeLines($projectDirectory);
    }

    /**
     * Moves a request this gateway built into the host's namespaces and refuses any other command.
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public static function onHost(array $command): array
    {
        return HostNamespaces::of($command);
    }

    /** Commands in the host's namespaces. */
    public static function host(): RootHost
    {
        return new RootHost;
    }

    /** Containers that fill a repository's build cache on the host's daemon. */
    public static function buildCache(): RootBuildCache
    {
        return new RootBuildCache;
    }

    /** The engine's root shell scripts. */
    public static function scripts(): RootScripts
    {
        return new RootScripts;
    }

    public static function users(): RootUsers
    {
        return new RootUsers;
    }

    /** The host's addresses, routes and listening sockets. */
    public static function network(): RootNetwork
    {
        return new RootNetwork;
    }

    /** ufw and fail2ban on the host. */
    public static function firewall(): RootFirewall
    {
        return new RootFirewall;
    }

    /** Host readings, and the signal sent to an account's dockerd. */
    public static function probes(): RootProbes
    {
        return new RootProbes;
    }

    public static function backupStore(): RootBackupStore
    {
        return new RootBackupStore;
    }

    /** Queues the webserver reload on the host. */
    public static function webserver(): RootWebserver
    {
        return new RootWebserver;
    }

    /** Commands run as an account, or as root for it. */
    public static function account(): AccountCommands
    {
        return new AccountCommands;
    }

    /** File commands as $uid:$gid inside the account's home. Root and a missing id are refused. */
    public static function accountFiles(int $uid, int $gid): AccountFiles
    {
        return new AccountFiles($uid, $gid);
    }

    /** File manager commands as $uid:$gid on paths a user names inside the home. */
    public static function accountFileManager(int $uid, int $gid): AccountFileManager
    {
        return new AccountFileManager($uid, $gid);
    }
}
