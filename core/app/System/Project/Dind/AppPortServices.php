<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Port\PortMapping;
use App\System\Project\Dind;

/**
 * The run file's services that publish the port the site is routed to. The
 * health sweep calls the application by them, and so does the log proxy.
 */
final class AppPortServices
{
    /**
     * @return list<string>|null null when none can be told
     */
    public static function names(Dind $project): ?array
    {
        $port = $project->userModel()->getAppPort();
        try {
            $compose = $port === null ? null : ComposeYaml::parseFile($project->userAppComposeFileToRun());
        } catch (\Throwable) {
            return null;
        }

        $names = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $name => $service) {
            foreach (is_array($service) && is_array($service['ports'] ?? null) ? $service['ports'] : [] as $entry) {
                if (PortMapping::parse($entry)?->hostPort === $port) {
                    $names[] = (string) $name;
                    break;
                }
            }
        }

        return $names === [] ? null : $names;
    }
}
