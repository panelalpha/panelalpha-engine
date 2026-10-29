<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Platform\Probes\ProcfileWebProbe;
use App\System\Project\Dind as DindProject;

/**
 * Reading a file out of the account's project directory.
 *
 * Always through the System API rather than PHP's own filesystem functions:
 * ~/project is inside the account container and root-owned on the host, so a
 * plain file_get_contents() would answer for the wrong tree — or for nothing
 * at all. {@see ProcfileWebProbe} reads host
 * paths directly and is right to; anything asking what the *account* has must
 * come through here.
 *
 * Absent and empty are one answer on purpose. Every caller is asking "is
 * there a composer.json to read", and a zero-byte one is not something to
 * hand a JSON parser.
 */
class ProjectFiles
{
    private DindProject $project;

    public function __construct(DindProject $project)
    {
        $this->project = $project;
    }

    /** Contents, or null when the file is absent or empty. */
    public function read(string $path): ?string
    {
        $system = $this->project->system();
        $fs = $system->filesystem();
        if (! $fs->fileExists($path)) {
            return null;
        }
        $contents = $fs->fileGetContents($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /** The same, for a file named relative to a project directory. */
    public function readIn(string $projectDir, string $name): ?string
    {
        return $this->read(rtrim($projectDir, '/').'/'.$name);
    }
}
