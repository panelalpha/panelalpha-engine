<?php

namespace Tests\Integration\Console;

use Tests\Support\LocalRoot;

/**
 * A root broker client that writes down every request and answers each from rules: the first rule whose action
 * matches, and whose text, when given, appears in the request, sets the output and exit code; anything else exits 0
 * with no output. Undo with {@see close()} in tearDown.
 */
final class ScriptedRoot
{
    private string $dir;

    /** @var list<array{action: string, contains: ?string, out: string, err: string, code: int}> */
    private array $rules = [];

    public function __construct()
    {
        $this->dir = sys_get_temp_dir().'/pa-scripted-root-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/pa-root', self::script());
        chmod($this->dir.'/pa-root', 0755);
        $this->writeRules();
        LocalRoot::answerWith($this->dir.'/pa-root');
    }

    public function on(string $action, string $out = '', int $code = 0, ?string $contains = null, string $err = ''): self
    {
        $this->rules[] = ['action' => $action, 'contains' => $contains, 'out' => $out, 'err' => $err, 'code' => $code];
        $this->writeRules();

        return $this;
    }

    /** Every request after this answers as the rules added from now on say. */
    public function reset(): self
    {
        $this->rules = [];
        $this->writeRules();

        return $this;
    }

    public function path(): string
    {
        return $this->dir.'/pa-root';
    }

    /** @return list<list<string>> each request's arguments, without the client */
    public function requests(): array
    {
        $lines = @file($this->dir.'/log', FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_map(static fn (string $line): array => json_decode($line, true), $lines));
    }

    /** @return list<string> */
    public function actions(): array
    {
        return array_map(static function (array $argv): string {
            $at = 0;
            while (isset($argv[$at]) && str_starts_with($argv[$at], '--')) {
                $at += $argv[$at] === '--env' ? 2 : 1;
            }

            return (string) ($argv[$at] ?? '');
        }, $this->requests());
    }

    public function close(): void
    {
        LocalRoot::answerByBroker();
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    private function writeRules(): void
    {
        file_put_contents($this->dir.'/rules.json', json_encode($this->rules, JSON_THROW_ON_ERROR));
    }

    private static function script(): string
    {
        return <<<'PY'
            #!/usr/bin/env python3
            import json, os, sys
            here = os.path.dirname(os.path.abspath(__file__))
            args = sys.argv[1:]
            with open(os.path.join(here, 'log'), 'a') as log:
                log.write(json.dumps(args) + '\n')
            at = 0
            while at < len(args) and args[at].startswith('--'):
                at += 2 if args[at] == '--env' else 1
            action = args[at] if at < len(args) else ''
            line = ' '.join(args)
            with open(os.path.join(here, 'rules.json')) as f:
                rules = json.load(f)
            for rule in rules:
                if rule['action'] == action and (rule['contains'] is None or rule['contains'] in line):
                    sys.stdout.write(rule['out'])
                    sys.stderr.write(rule['err'])
                    sys.exit(rule['code'])
            sys.exit(0)
            PY;
    }
}
