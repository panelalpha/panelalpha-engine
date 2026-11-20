<?php

namespace Tests\Support;

use App\System\Privileged\Root;

/**
 * A pa-root that records every request and answers each `area.action` with canned output, so code that builds its
 * own `new System` reaches no host. Undo with {@see uninstall()} in tearDown.
 */
final class ScriptedBroker
{
    private string $dir;

    /** @var array<string, list<array{match: string|list<string>|null, exactly: bool, stdout: string, stderr: string, exit: int, write: ?string}>> */
    private array $answers = [];

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/pa-scripted-broker-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        file_put_contents($this->dir.'/pa-root', self::script($this->dir));
        chmod($this->dir.'/pa-root', 0755);
        $this->save();
        Root::answerWith($this->dir.'/pa-root');
    }

    public function uninstall(): void
    {
        Root::answerWith(null);
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /**
     * Answers $action, or only a request one of whose values contains $match (is $match, when $exactly; holds every
     * one of them as a value, when $match is a list), with $stdout and $exit. The latest answer that fits wins; a
     * request no answer fits exits 0 with no output.
     *
     * @param  string|list<string>|null  $match
     */
    public function answer(
        string $action,
        string $stdout = '',
        int $exit = 0,
        string $stderr = '',
        string|array|null $match = null,
        ?string $write = null,
        bool $exactly = false,
    ): self {
        $this->answers[$action] ??= [];
        array_unshift($this->answers[$action], ['match' => $match, 'exactly' => $exactly, 'stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit, 'write' => $write]);
        $this->save();

        return $this;
    }

    /** A `files.copy` of $source out of the host hands back $contents. */
    public function hostFile(string $source, string $contents): self
    {
        return $this->answer('files.copy', match: $source, write: $contents);
    }

    /** @return list<list<string>> every request, as the argv the client was given */
    public function requests(): array
    {
        $log = $this->dir.'/requests.log';
        if (! is_file($log)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true),
            array_values(array_filter(explode("\n", (string) file_get_contents($log)))),
        );
    }

    /** @return list<string> the `area.action` of every request, in order */
    public function actions(): array
    {
        return array_map(fn (array $argv): string => (string) (Root::askedAction([$this->dir.'/pa-root', ...$argv]) ?? ''), $this->requests());
    }

    /** @return list<list<string>> the values of every request for $action */
    public function valuesOf(string $action): array
    {
        $values = [];
        foreach ($this->requests() as $argv) {
            $full = [$this->dir.'/pa-root', ...$argv];
            if (Root::askedAction($full) === $action) {
                $values[] = Root::askedValues($full);
            }
        }

        return $values;
    }

    private function save(): void
    {
        file_put_contents($this->dir.'/answers.json', json_encode($this->answers));
    }

    private static function script(string $dir): string
    {
        $dir = var_export($dir, true);

        return <<<PHP
            #!/usr/bin/env php
            <?php
            \$dir = {$dir};
            \$argv = array_slice(\$_SERVER['argv'], 1);
            file_put_contents(\$dir.'/requests.log', json_encode(\$argv)."\\n", FILE_APPEND);
            \$at = array_search('--', \$argv, true);
            \$action = \$at === false ? '' : (string) (\$argv[\$at - 1] ?? '');
            \$values = \$at === false ? [] : array_slice(\$argv, \$at + 1);
            \$answers = json_decode((string) file_get_contents(\$dir.'/answers.json'), true) ?: [];
            foreach (\$answers[\$action] ?? [] as \$answer) {
                if (is_array(\$answer['match'])) {
                    if (array_diff(\$answer['match'], \$values) !== []) {
                        continue;
                    }
                } elseif (\$answer['match'] !== null) {
                    \$fits = static fn (\$v) => \$answer['exactly'] ? (string) \$v === \$answer['match'] : str_contains((string) \$v, \$answer['match']);
                    if (! array_filter(\$values, \$fits)) {
                        continue;
                    }
                }
                if (\$answer['write'] !== null && \$values !== []) {
                    file_put_contents((string) end(\$values), \$answer['write']);
                }
                fwrite(STDOUT, \$answer['stdout']);
                fwrite(STDERR, \$answer['stderr']);
                exit(\$answer['exit']);
            }
            exit(0);
            PHP;
    }
}
