<?php

namespace App\Lib\Deploy\Detect;

/**
 * The stage a repository Dockerfile should be built to. Docker builds the last stage, and a Dockerfile that ends
 * in its development stage then deploys a dev server: karlomikus/vue-salt-rim ends `FROM oven/bun:1 AS dev` with
 * `bun run dev` after its `prod` nginx stage, and the site answered Vite's 403 instead of the app.
 *
 * No Laravel dependencies — unit-testable.
 */
final class DockerfileStages
{
    /** A stage name for a workstation or a test run, never the deployment. */
    private const DEVELOPMENT_NAME = '/^(dev|devel|develop|development|local|debug|test|tests|testing|ci|e2e|lint|storybook|devcontainer)$|[-_.](dev|development|debug|test|testing)$/i';

    private const START_PATTERN = '/^\s*(?:CMD|ENTRYPOINT)\s/mi';

    /**
     * The stage to build instead of the last one: when the last is a development stage, the nearest named stage
     * before it that starts the app itself (its own CMD or ENTRYPOINT); null to build the default.
     */
    public static function productionTarget(string $contents): ?string
    {
        $stages = self::stages($contents);
        $last = array_pop($stages);
        if ($last === null || $last['name'] === null || preg_match(self::DEVELOPMENT_NAME, $last['name']) !== 1) {
            return null;
        }
        foreach (array_reverse($stages) as $stage) {
            if (self::startsTheApp($stage)) {
                return $stage['name'];
            }
        }

        return null;
    }

    /** The text of the stage a build to $target produces, or the whole file when there is no such stage. */
    public static function stageText(string $contents, ?string $target): string
    {
        foreach ($target === null ? [] : self::stages($contents) as $stage) {
            if ($stage['name'] !== null && strcasecmp($stage['name'], $target) === 0) {
                return $stage['text'];
            }
        }

        return $contents;
    }

    /** @param  array{name: ?string, text: string}  $stage */
    private static function startsTheApp(array $stage): bool
    {
        return $stage['name'] !== null
            && preg_match(self::DEVELOPMENT_NAME, $stage['name']) !== 1
            && preg_match(self::START_PATTERN, $stage['text']) === 1;
    }

    /**
     * @return list<array{name: ?string, text: string}>
     */
    private static function stages(string $contents): array
    {
        $stages = [];
        foreach (preg_split("/\r\n|\n|\r/", $contents) ?: [] as $line) {
            if (preg_match(DockerfileFinder::FROM_LINE_PATTERN, $line, $m) === 1) {
                $stages[] = ['name' => ($m[2] ?? '') === '' ? null : $m[2], 'text' => ''];
            }
            if ($stages !== []) {
                $stages[count($stages) - 1]['text'] .= $line."\n";
            }
        }

        return $stages;
    }
}
