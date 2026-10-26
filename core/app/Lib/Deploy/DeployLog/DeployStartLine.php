<?php

namespace App\Lib\Deploy\DeployLog;

/**
 * The `Deploy started (source: …)` lines a deploy log opens with, and what
 * they say about where the deploy came from.
 */
final class DeployStartLine
{
    /** How many lines from the start of a log the start lines are looked for in. */
    public const HEAD = 200;

    private const OPENING = 'Deploy started (';

    /** Sources as the log spells them, renamed where the API has its own word. */
    private const RENAMED = ['push' => 'hook', 'dind template' => 'template'];

    /** Sources a deploy that came from somewhere else continues under. */
    private const FOLLOW_ON = ['rebuild', 'template'];

    /** A project's first deploy, the one its create runs. */
    public const KIND_INSTALL = 'install';

    public const KIND_REDEPLOY = 'redeploy';

    /** Only a create names the repository or the template it starts from. */
    private const INSTALL_SOURCE = 'dind template';

    private const CLONE_BRANCH = '/^(?:Cloning repository .+|Repository cloned) \(branch: ([^)]+)\)$/';

    /**
     * The start line a redeploy writes; empty fields are left out.
     *
     * @param  array<string, ?string>  $fields
     */
    public static function text(string $source, array $fields = []): string
    {
        $parts = ['source: '.$source];
        foreach ($fields as $key => $value) {
            if ($value !== null && $value !== '') {
                $parts[] = $key.': '.$value;
            }
        }

        return self::OPENING.implode(', ', $parts).')';
    }

    /**
     * An archive deploy logs `archive` and then `rebuild`, and a template
     * account that later receives files logs `template` first, so the first
     * source that is not a follow-on wins. The branch is the one a start line
     * names, or the one the create cloned.
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
            $branch ??= self::clonedBranch($line['msg']);
            $fields = self::fields($line['msg']);
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

    private static function clonedBranch(string $msg): ?string
    {
        return preg_match(self::CLONE_BRANCH, $msg, $match) === 1 ? $match[1] : null;
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
    private static function fields(string $msg): ?array
    {
        if (! str_starts_with($msg, self::OPENING) || ! str_ends_with($msg, ')')) {
            return null;
        }

        $fields = [];
        foreach (explode(', ', substr($msg, strlen(self::OPENING), -1)) as $pair) {
            [$key, $value] = array_pad(explode(': ', $pair, 2), 2, '');
            $fields[$key] = $value;
        }

        return $fields;
    }
}
