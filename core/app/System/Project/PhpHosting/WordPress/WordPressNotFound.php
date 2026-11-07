<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * No running container of the project has both PHP and a WordPress tree at the requested path for WP-CLI.
 * A RuntimeException like the runner's other failures, so every caller that does not ask for it reports it as before.
 */
class WordPressNotFound extends \RuntimeException {}
