<?php

namespace App\System\Privileged;

/** Root docker commands as quoted shell lines, for use inside a pipeline. */
final class RootDockerLines
{
    private const AREA = 'lines';

    public function inspectImage(string $image): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    public function imageLabel(string $image, string $label): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image), Arguments::name($label)]);
    }

    public function pullQuietly(string $image): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    public function pushQuietly(string $image): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    public function tag(string $source, string $target): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($source), Arguments::image($target)]);
    }

    public function removeImage(string $image): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    /** The Dockerfile comes on stdin. */
    public function buildFromStdin(string $tag, bool $pull): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($tag), $pull ? 'pull' : '']);
    }

    public function buildQuietly(string $tag, string $context): string
    {
        return $this->docker(__FUNCTION__, [Arguments::image($tag), Arguments::path($context)]);
    }

    public function isRunning(string $container): string
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @param  list<string>  $values */
    private function docker(string $action, array $values): string
    {
        return Root::actionLine(self::AREA, $action, $values);
    }
}
