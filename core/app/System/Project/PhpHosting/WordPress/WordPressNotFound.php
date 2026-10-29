<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * The runner found no WordPress to run WP-CLI against: on a project in its
 * own containers, no running container has both PHP and a WordPress tree at
 * the requested path. A RuntimeException like the runner's other failures, so
 * every caller that does not ask for it reports it as before.
 */
class WordPressNotFound extends \RuntimeException {}
