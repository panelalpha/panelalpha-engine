<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * The engine must-use plugin for WordPress in a project's own container.
 *
 * The install is not on the host filesystem, so WordPress writes the file
 * itself through WP-CLI, into WPMU_PLUGIN_DIR and as the user WP-CLI runs as.
 */
final class ContainerMustUsePlugin implements EnsuresMustUsePlugin
{
    public function __construct(
        private WpCli $cli,
        private MustUsePlugin $source,
    ) {}

    /** The install root is resolved in the container; this one is ignored. */
    public function ensure(string $installRoot): void
    {
        $contents = @file_get_contents($this->source->sourcePath());
        if ($contents === false) {
            throw new Exception('Engine must-use plugin source is missing.', 500);
        }

        $this->cli->runOrFail(['eval', self::writeCode($contents)]);
    }

    public static function writeCode(string $contents): string
    {
        return sprintf(
            '$c = base64_decode(%s); $d = WPMU_PLUGIN_DIR; $f = $d . "/panelalpha-app.php";'
            .' if (@file_get_contents($f) === $c) { return; }'
            .' if ((!is_dir($d) && !wp_mkdir_p($d)) || file_put_contents($f, $c) === false) {'
            .' WP_CLI::error("Cannot write $f."); }',
            var_export(base64_encode($contents), true)
        );
    }
}
