<?php

namespace App\Console\Commands\Concerns;

use App\Exceptions\ProblemException;
use App\Lib\Deploy\CacheManager\OperatorImages;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The build an operator image command is given: the Dockerfile on standard
 * input, and `--build-arg=NAME=value` as often as needed.
 */
trait ReadsOperatorImageBuild
{
    protected function dockerfileFromStdin(string $usage): string
    {
        if (stream_isatty(STDIN)) {
            throw ProblemException::one('dockerfile', OperatorImages::DOCKERFILE_REQUIRED, "Pipe the Dockerfile in: {$usage}");
        }

        return (string) stream_get_contents(STDIN);
    }

    /**
     * @return array<string, string>
     */
    protected function buildArgOptions(): array
    {
        $args = [];
        foreach ((array) $this->option('build-arg') as $pair) {
            $equals = strpos((string) $pair, '=');
            if ($equals === false || $equals === 0) {
                throw ProblemException::one('build_args', OperatorImages::BUILD_ARGS_INVALID, "--build-arg takes NAME=value, not '{$pair}'.");
            }
            $args[substr((string) $pair, 0, $equals)] = substr((string) $pair, $equals + 1);
        }

        return $args;
    }

    protected function writeOutput(string $chunk): void
    {
        $this->output->write($chunk, false, OutputInterface::OUTPUT_RAW);
    }
}
