<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * A package.json script command and the scripts it runs by name, followed a few levels, without running anything.
 */
final class ScriptChain
{
    /** `npm run x`, `pnpm [run] x` or `yarn [run] x` naming a script. */
    private const SCRIPT_CALL = '/(?:^|&&|\|\||;)\s*(?:npm\s+run|pnpm(?:\s+run)?|yarn(?:\s+run)?)\s+([\w:.-]+)/';

    private const MAX_DEPTH = 3;

    /**
     * Does $command, or a script it calls, match $pattern?
     *
     * @param  \Closure(string): string  $script  a script's command by name, '' when there is none
     */
    public static function mentions(string $command, string $pattern, \Closure $script, int $depth = 0): bool
    {
        if (preg_match($pattern, $command) === 1) {
            return true;
        }
        if ($depth >= self::MAX_DEPTH || preg_match_all(self::SCRIPT_CALL, $command, $calls) === 0) {
            return false;
        }
        foreach ($calls[1] as $name) {
            $body = $script($name);
            if ($body !== '' && self::mentions($body, $pattern, $script, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
