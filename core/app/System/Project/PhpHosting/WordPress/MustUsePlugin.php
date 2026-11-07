<?php

namespace App\System\Project\PhpHosting\WordPress;

use App\Models\User;

/**
 * Engine must-use plugin for one-time panelalpha_sso tokens, the file DinD WordPress ships in its image.
 * PHP hosting installs get no bake, so SSO copies it into the install's mu-plugins before returning a login URL.
 */
final class MustUsePlugin implements EnsuresMustUsePlugin
{
    public const RELATIVE_PATH = 'wp-content/mu-plugins/panelalpha-app.php';

    /**
     * Runs before WordPress loads and mends, in place, a must-use plugin copy that takes every PHP CLI run for its own
     * script, which keeps WP-CLI from running.
     */
    public const MEND_ON_LOAD = <<<'PHP'
        $f = rtrim((string) (WP_CLI::get_runner()->config['path'] ?? ''), '/') . '/wp-content/mu-plugins/panelalpha-app.php';
        $c = is_file($f) ? (string) file_get_contents($f) : '';
        $n = str_replace("if (PHP_SAPI === 'cli') {", "if (PHP_SAPI === 'cli' && (get_included_files()[0] ?? '') === __FILE__) {", $c);
        if ($n !== $c && is_writable($f)) { file_put_contents($f, $n); }
        PHP;

    public function __construct(
        private User $user,
    ) {}

    public function sourcePath(): string
    {
        return resource_path(
            'sources/github.com/wordpress/wordpress/files/wp-content/mu-plugins/panelalpha-app.php'
        );
    }

    /**
     * Ensure the engine MU plugin is present under the WordPress install root.
     */
    public function ensure(string $installRoot): void
    {
        $source = $this->sourcePath();
        if (! is_file($source)) {
            throw new Exception('Engine must-use plugin source is missing.', 500);
        }

        $contents = file_get_contents($source);
        if ($contents === false) {
            throw new Exception('Engine must-use plugin source is unreadable.', 500);
        }

        $installRoot = rtrim($installRoot, '/');
        $muDir = $installRoot.'/wp-content/mu-plugins';
        $dest = $installRoot.'/'.self::RELATIVE_PATH;

        $files = $this->user->project()->fileManager();
        if (! $files->exists($muDir)) {
            $files->mkdir($muDir, parents: true);
        }
        $files->putContents($dest, $contents);
    }
}
