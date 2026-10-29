<?php

namespace App\System\Project\PhpHosting\WordPress;

/**
 * A WP-CLI database command found WordPress only in containers without the
 * MySQL client it shells out to. A RuntimeException like the runner's other
 * failures, so a database route refuses with its message.
 */
class DatabaseClientMissing extends \RuntimeException {}
