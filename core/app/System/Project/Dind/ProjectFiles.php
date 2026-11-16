<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Platform\Probes\ProcfileWebProbe;
use App\System\Project\Dind as DindProject;

/**
 * Reading a file out of ~/project through the System API, never PHP's own functions, which would read the wrong tree
 * ({@see ProcfileWebProbe} reads host paths). Absent and empty are one answer: a zero-byte file is no JSON to parse.
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
