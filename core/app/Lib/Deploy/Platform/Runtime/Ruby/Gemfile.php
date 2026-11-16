<?php

namespace App\Lib\Deploy\Platform\Runtime\Ruby;

use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The project's Gemfile. Only the `gem "name"` lines matter: what the app runs
 * on decides the base image's apt packages and the server the container starts.
 */
final class Gemfile
{
    public const FILENAME = 'Gemfile';

    public const LOCKFILE = 'Gemfile.lock';

    private function __construct(private readonly string $contents, private readonly string $lock = '') {}

    public static function of(ProjectContext $project): self
    {
        return new self($project->contents(self::FILENAME) ?? '', $project->contents(self::LOCKFILE) ?? '');
    }

    /** Same, for a caller that has the text. */
    public static function fromContents(string $contents, string $lock = ''): self
    {
        return new self($contents, $lock);
    }

    /**
     * Whether Gemfile.lock resolves the gem, directly or not (Diaspora's idn-ruby): a spec line at four spaces,
     * not a dependency line at six.
     */
    public function locks(string $gem): bool
    {
        return preg_match('/^ {4}'.preg_quote($gem, '/').' \(/m', $this->lock) === 1;
    }

    public function requires(string $gem): bool
    {
        $pattern = "/^\s*gem\s+['\"]".preg_quote($gem, '/')."['\"]/m";

        return preg_match($pattern, $this->contents) === 1;
    }

    /**
     * Groups the image does not install (`BUNDLE_WITHOUT="development:test"` in ruby.yaml and the template).
     *
     * @var list<string>
     */
    private const EXCLUDED_GROUPS = ['development', 'test'];

    /**
     * Whether the image installs the gem, outside excluded `group ... do` blocks and `group:` options:
     * Redmine's test-only `puma` exited 127.
     */
    public function requiresAtRuntime(string $gem): bool
    {
        if (! $this->requires($gem)) {
            return false;
        }

        $quoted = preg_quote($gem, '/');
        $groups = [];
        foreach (preg_split('/\R/', $this->contents) ?: [] as $line) {
            $trimmed = trim($line);
            if (preg_match('/^group\s+(.+?)\s+do\b/', $trimmed, $open) === 1) {
                $groups[] = self::namesIn($open[1]);

                continue;
            }
            if ($trimmed === 'end' && $groups !== []) {
                array_pop($groups);

                continue;
            }
            if (preg_match("/^gem\s+['\"]{$quoted}['\"](.*)$/", $trimmed, $decl) !== 1) {
                continue;
            }

            $enclosing = $groups === [] ? [] : array_merge(...$groups);
            $inline = preg_match('/\bgroups?:\s*(.+)$/', $decl[1], $opt) === 1
                ? self::namesIn($opt[1])
                : [];
            $named = array_merge($enclosing, $inline);

            if ($named === [] || array_diff($named, self::EXCLUDED_GROUPS) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The group names in `:test`, `:development, :test` or `[:a, :b]`.
     *
     * @return list<string>
     */
    private static function namesIn(string $raw): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)|["\']([^"\']+)["\']/', $raw, $m, PREG_SET_ORDER);

        $names = [];
        foreach ($m as $match) {
            $name = $match[1] !== '' ? $match[1] : ($match[2] ?? '');
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The `ruby` directive's constraints as written (`ruby '>= 3.2', '< 4.0'` is two); empty without one, or when
     * `file:`/`ENV[...]` defer to a version source already read.
     *
     * @return list<string>
     */
    public function rubyConstraints(): array
    {
        if (preg_match('/^\s*ruby\s+(.+)$/m', $this->contents, $line) !== 1) {
            return [];
        }
        if (preg_match('/^\s*(file|ENV)\b/i', trim($line[1])) === 1) {
            return [];
        }

        $constraints = [];
        if (preg_match_all('/[\'"]([^\'"]+)[\'"]/', $line[1], $matches) >= 1) {
            foreach ($matches[1] as $constraint) {
                $constraint = trim($constraint);
                if ($constraint !== '') {
                    $constraints[] = $constraint;
                }
            }
        }

        return $constraints;
    }

    /**
     * @param  list<string>  $gems
     */
    public function requiresAny(array $gems): bool
    {
        foreach ($gems as $gem) {
            if ($this->requires($gem)) {
                return true;
            }
        }

        return false;
    }
}
