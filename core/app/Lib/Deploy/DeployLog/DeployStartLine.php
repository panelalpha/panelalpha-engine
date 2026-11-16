<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * The `Deploy started (source: …)` and `Deploy requested (…)` lines a deploy log opens with: where it came from and who
 * asked. A start line is written before the clone, so the line the clone ends on names the commit.
 */
final class DeployStartLine
{
    /** How many lines from the start of a log the start lines are looked for in. */
    public const HEAD = 200;

    private const OPENING = 'Deploy started (';

    private const REQUEST_OPENING = 'Deploy requested (';

    private const CLONED = 'Repository cloned';

    private const CLONED_OPENING = self::CLONED.' (';

    /** Sources as the log spells them, renamed where the API has its own word. */
    private const RENAMED = ['push' => 'hook', 'dind template' => 'template'];

    /** Sources a deploy that came from somewhere else continues under. */
    private const FOLLOW_ON = ['rebuild', 'template'];

    /** A project's first deploy, the one its create runs. */
    public const KIND_INSTALL = 'install';

    public const KIND_REDEPLOY = 'redeploy';

    /** Only a create names the repository or the template it starts from. */
    private const INSTALL_SOURCE = 'dind template';

    private const CLONING_BRANCH = '/^Cloning repository .+ \(branch: ([^)]+)\)$/';

    /**
     * The start line a redeploy writes; empty fields are left out.
     *
     * @param  array<string, ?string>  $fields
     */
    public static function text(string $source, array $fields = []): string
    {
        return self::OPENING.implode(', ', ['source: '.$source, ...self::pairs($fields)]).')';
    }

    /** Who asked for a deploy and the task that runs it; null when none of it is known. */
    public static function requestText(?int $taskId, ?string $trigger, ?string $action): ?string
    {
        $pairs = self::pairs(['trigger' => $trigger, 'action' => $action, 'task' => $taskId === null ? null : (string) $taskId]);

        return $pairs === [] ? null : self::REQUEST_OPENING.implode(', ', $pairs).')';
    }

    /**
     * The last request line among $lines: a deploy continued by a later task names it after the first.
     *
     * @param  list<array{msg: string}>  $lines
     * @return array{trigger: ?string, action: ?string, task_id: ?int}
     */
    public static function request(array $lines): array
    {
        $fields = [];
        foreach ($lines as $line) {
            $fields = self::fields($line['msg'], self::REQUEST_OPENING) ?? $fields;
        }
        $task = self::value($fields, 'task');

        return [
            'trigger' => self::value($fields, 'trigger'),
            'action' => self::value($fields, 'action'),
            'task_id' => $task !== null && ctype_digit($task) ? (int) $task : null,
        ];
    }

    /** The line a clone ends on, naming the branch and the commit it checked out when git could tell. */
    public static function cloned(?string $branch, ?string $commit): string
    {
        $pairs = self::pairs(['branch' => $branch, 'commit' => $commit]);

        return $pairs === [] ? self::CLONED : self::CLONED_OPENING.implode(', ', $pairs).')';
    }

    /**
     * @param  array<string, ?string>  $fields
     * @return list<string>
     */
    private static function pairs(array $fields): array
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $pairs[] = $key.': '.$value;
            }
        }

        return $pairs;
    }

    /**
     * The first source that is not a follow-on wins (an archive logs `archive` then `rebuild`, a template account `template`
     * first); the commit and branch are a start line's, or the ones the clone checked out.
     *
     * @param  list<array{msg: string}>  $lines
     * @return array{source: ?string, commit: ?string, branch: ?string, kind: ?string}
     */
    public static function read(array $lines): array
    {
        $sources = [];
        $commit = null;
        $branch = null;
        $install = false;
        foreach ($lines as $line) {
            $cloned = self::fields($line['msg'], self::CLONED_OPENING) ?? [];
            $commit ??= self::value($cloned, 'commit');
            $branch ??= self::cloningBranch($line['msg']) ?? self::value($cloned, 'branch');
            $fields = self::fields($line['msg'], self::OPENING);
            if ($fields === null) {
                continue;
            }
            if (isset($fields['source'])) {
                $sources[] = self::RENAMED[$fields['source']] ?? $fields['source'];
            }
            $commit ??= self::value($fields, 'commit');
            $branch ??= self::value($fields, 'branch');
            $install = $install || isset($fields['repo']) || ($fields['source'] ?? null) === self::INSTALL_SOURCE;
        }

        return [
            'source' => self::origin($sources),
            'commit' => $commit,
            'branch' => $branch,
            'kind' => $sources === [] ? null : ($install ? self::KIND_INSTALL : self::KIND_REDEPLOY),
        ];
    }

    /**
     * @param  array<string, string>  $fields
     */
    private static function value(array $fields, string $key): ?string
    {
        return ($fields[$key] ?? '') !== '' ? $fields[$key] : null;
    }

    private static function cloningBranch(string $msg): ?string
    {
        return preg_match(self::CLONING_BRANCH, $msg, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param  list<string>  $sources
     */
    private static function origin(array $sources): ?string
    {
        foreach ($sources as $source) {
            if (! in_array($source, self::FOLLOW_ON, true)) {
                return $source;
            }
        }

        return $sources[0] ?? null;
    }

    /**
     * @return array<string, string>|null
     */
    private static function fields(string $msg, string $opening): ?array
    {
        if (! str_starts_with($msg, $opening) || ! str_ends_with($msg, ')')) {
            return null;
        }

        $fields = [];
        foreach (explode(', ', substr($msg, strlen($opening), -1)) as $pair) {
            [$key, $value] = array_pad(explode(': ', $pair, 2), 2, '');
            $fields[$key] = $value;
        }

        return $fields;
    }
}
