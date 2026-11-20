<?php

namespace Tests\Support;

use App\System\ComposeProject;

trait AccountContainersFixtures
{
    private const USERS = '/opt/panelalpha/shared-hosting/users';

    /** `docker ps` lines in the shape the engine asks for, labels as Compose writes them on a host. */
    private const PS = ComposeProject::NAME . "-core-1\trunning\t/opt/panelalpha/shared-hosting\n"
        . "rvgotify\trunning\t" . self::USERS . "/rvgotify\n"
        . "swkorphan\texited\t" . self::USERS . "/swkorphan\n"
        . "hubtest\trunning\t/root/hubtest\n"
        . "pwbd1c1d74\trunning\t" . self::USERS . "/pwbd1c1d74\n";
}
