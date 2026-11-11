<?php

namespace App\System\Privileged;

/** {@see RootAccountDocker} requests as quoted shell lines, for use inside a pipeline. */
final class RootAccountDockerLines
{
    private const AREA = 'account-docker';

    public function __construct(private readonly string $username) {}

    /** Prints the image's inspect as a one-item JSON list, `[null]` when the daemon does not have it. */
    public function images(string $image): string
    {
        return $this->ask(__FUNCTION__, [Arguments::image($image)]);
    }

    public function hasImage(string $image): string
    {
        return $this->ask(__FUNCTION__, [Arguments::image($image)]);
    }

    /** With $loginDirectory, the project's registry login there goes with the pull. */
    public function pull(string $image, ?string $loginDirectory = null): string
    {
        return $this->ask(__FUNCTION__, [Arguments::image($image), $loginDirectory === null ? '' : Arguments::path($loginDirectory)]);
    }

    public function tag(string $source, string $target): string
    {
        return $this->ask(__FUNCTION__, [Arguments::image($source), Arguments::image($target)]);
    }

    public function removeImage(string $image): string
    {
        return $this->ask('removeImages', ['', Arguments::image($image)]);
    }

    /** @param  list<string>  $values */
    private function ask(string $action, array $values): string
    {
        return Root::actionLine(self::AREA, $action, [Arguments::username($this->username), ...$values]);
    }
}
