<?php

namespace App\System\Project\Backup;

use App\Support\JobDeadline;
use App\System\Project as UserProject;

/** Everything a backup captures and a restore puts back, one class per kind of item. */
final readonly class ProjectContent
{
    public function __construct(
        public ProjectFiles $files,
        public HomeFiles $home,
        public ProjectVolumes $volumes,
        public ProjectDatabases $databases,
    ) {}

    public static function of(UserProject $project, JobDeadline $deadline): self
    {
        return new self(
            new ProjectFiles($project, $deadline),
            new HomeFiles($project, $deadline),
            new ProjectVolumes($project, $deadline),
            new ProjectDatabases($project, $deadline),
        );
    }
}
