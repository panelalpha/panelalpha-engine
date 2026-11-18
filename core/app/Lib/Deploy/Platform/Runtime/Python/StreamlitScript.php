<?php

namespace App\Lib\Deploy\Platform\Runtime\Python;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * The root script a Streamlit app is started from, `streamlit run <script>`: run as `python <script>` it executes once
 * in bare mode and exits, with nothing bound. `streamlit_app.py` is Streamlit's own default name; otherwise only the
 * one root script importing Streamlit, as a page of helpers importing it too is a guess.
 */
final class StreamlitScript
{
    private const DEFAULT_NAME = 'streamlit_app.py';

    private const IMPORT = '/^[ \t]*(?:import[ \t]+streamlit\b|from[ \t]+streamlit\b)/m';

    public static function find(string $projectDir): ?string
    {
        $scripts = self::importingStreamlit(rtrim($projectDir, '/'));
        if (in_array(self::DEFAULT_NAME, $scripts, true)) {
            return self::DEFAULT_NAME;
        }

        return count($scripts) === 1 ? $scripts[0] : null;
    }

    /** @return list<string> */
    private static function importingStreamlit(string $projectDir): array
    {
        $scripts = [];
        foreach (ProjectContext::entries($projectDir) as $entry) {
            if (! str_ends_with($entry, '.py') || ! is_file($projectDir.'/'.$entry) || self::isTooling($entry)) {
                continue;
            }
            $contents = AccountHomeRead::contents($projectDir.'/'.$entry);
            if (is_string($contents) && preg_match(self::IMPORT, $contents) === 1) {
                $scripts[] = $entry;
            }
        }

        return $scripts;
    }

    private static function isTooling(string $name): bool
    {
        return preg_match('/^(?:test_.*|.*_test|conftest|setup|noxfile)\.py$/i', $name) === 1;
    }
}
