<?php

namespace Tests\Unit\Git;

/**
 * Every host command the git layer runs starts with `sudo` (or `docker` /
 * `nsenter`), so a shim first on PATH answers them from a rule table: the
 * real engine code runs end to end and nothing reaches the host.
 */
trait FakesGitHost
{
    private string $shim;
    private string|false $oldPath;
    private int $rules = 0;

    private function fakeGitHost(): void
    {
        $this->shim = sys_get_temp_dir() . '/pa-git-shim-' . bin2hex(random_bytes(4));
        mkdir($this->shim);
        $script = <<<'SH'
            #!/bin/sh
            d=$(dirname "$0")
            printf '%s\n' "$*" >> "$d/calls.log"
            tab=$(printf '\t')
            while IFS="$tab" read -r pat code out; do
              [ -z "$pat" ] && continue
              case "$*" in
                *"$pat"*)
                  if [ "$code" = 0 ]; then cat "$d/$out"; else cat "$d/$out" >&2; fi
                  exit "$code";;
              esac
            done < "$d/rules"
            exit 0
            SH;
        foreach (['sudo', 'docker', 'nsenter'] as $bin) {
            file_put_contents("{$this->shim}/{$bin}", $script);
            chmod("{$this->shim}/{$bin}", 0755);
        }
        touch("{$this->shim}/rules");
        touch("{$this->shim}/calls.log");

        $this->oldPath = getenv('PATH');
        $this->setPath($this->shim . ':' . (string) $this->oldPath);
    }

    private function restoreHost(): void
    {
        if ($this->oldPath !== false) {
            $this->setPath($this->oldPath);
        }
        foreach (glob($this->shim . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->shim);
    }

    // Symfony Process builds the child's environment from $_ENV and $_SERVER too.
    private function setPath(string $path): void
    {
        putenv('PATH=' . $path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
    }

    /** The first rule whose text occurs in a command's argv answers it; no rule is exit 0, no output. */
    private function respond(string $pattern, int $code = 0, string $output = ''): void
    {
        $file = 'out-' . (++$this->rules);
        file_put_contents("{$this->shim}/{$file}", $output);
        file_put_contents("{$this->shim}/rules", "{$pattern}\t{$code}\t{$file}\n", FILE_APPEND);
    }

    private function withRepository(): void
    {
        $this->respond("'rev-parse' '--is-inside-work-tree'", 0, "true\n");
    }

    private function withoutRepository(): void
    {
        $this->respond("'rev-parse' '--is-inside-work-tree'", 128, "fatal: not a git repository\n");
    }

    /** @return list<string> */
    private function calls(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents("{$this->shim}/calls.log"))));
    }

    private function called(string $needle): bool
    {
        foreach ($this->calls() as $call) {
            if (str_contains($call, $needle)) {
                return true;
            }
        }

        return false;
    }
}
