<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;

/**
 * The image an account had under a tag a push rebuilds from the pushed tree, held under `<repository>:pre-push`
 * meanwhile, so a target whose run file names that tag does not come back from a failed push on the pushed image.
 */
final class HeldImage
{
    private const TIMEOUT_SECONDS = 30;

    private function __construct(
        private DindProject $project,
        private string $image,
    ) {}

    /** Null when the account has no image under $image. */
    public static function hold(DindProject $project, string $image): ?self
    {
        if (! $project->innerDocker()->seeding()->hasImage($image)) {
            return null;
        }
        $held = new self($project, $image);
        $project->accountDaemon()->tag($image, $held->holdingTag(), self::TIMEOUT_SECONDS);

        return $held;
    }

    public function putBack(): void
    {
        $this->project->accountDaemon()->tag($this->holdingTag(), $this->image, self::TIMEOUT_SECONDS);
    }

    public function release(): void
    {
        $this->project->accountDaemon()->removeImages([$this->holdingTag()], true, self::TIMEOUT_SECONDS);
    }

    public function holdingTag(): string
    {
        return (preg_replace('/:[^:\/]*$/', '', $this->image) ?? $this->image).':pre-push';
    }
}
