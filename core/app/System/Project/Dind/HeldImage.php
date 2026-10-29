<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind as DindProject;

/**
 * The image an account had under a tag a push builds again from the pushed
 * tree, held under `<repository>:pre-push` meanwhile. A target whose own run
 * file names that tag would otherwise come back from a failed push on the
 * pushed image.
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
        $held->docker(['tag', $image, $held->holdingTag()]);

        return $held;
    }

    public function putBack(): void
    {
        $this->docker(['tag', $this->holdingTag(), $this->image]);
    }

    public function release(): void
    {
        $this->docker(['rmi', '-f', $this->holdingTag()]);
    }

    public function holdingTag(): string
    {
        return (preg_replace('/:[^:\/]*$/', '', $this->image) ?? $this->image).':pre-push';
    }

    /**
     * @param  list<string>  $args
     */
    private function docker(array $args): void
    {
        $this->project->shell()->execQuiet(['docker', ...$args], [], self::TIMEOUT_SECONDS);
    }
}
