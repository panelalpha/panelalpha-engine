<?php
/**
 * Omeka S reads this file after application/config/module.config.php and
 * merges it over the top (application.config.php lists it in
 * `module_listener_options.config_glob_paths`). The repository ships it as
 * local.config.php.dist and .gitignore's the real name, so this is the file
 * an operator was always going to edit; it is installed with one change made.
 */
return [
    'service_manager' => [
        'aliases' => [
            // Omeka's default thumbnailer shells out to ImageMagick's
            // `convert` binary. The shared PHP base image ships the `imagick`
            // PHP extension and no ImageMagick CLI at all, so the default
            // would fail on every uploaded image -- at upload time, long after
            // the deploy said it was fine. The Imagick thumbnailer is Omeka's
            // own implementation over the extension that is actually there.
            'Omeka\File\Thumbnailer' => 'Omeka\File\Thumbnailer\Imagick',
        ],
    ],
];
