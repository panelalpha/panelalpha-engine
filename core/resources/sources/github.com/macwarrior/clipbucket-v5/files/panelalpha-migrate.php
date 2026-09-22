<?php
/*
 * Run ClipBucket's own database migrations from the deploy.
 *
 * ClipBucket migrates through cb_install/sql/<version>/M<nnnnn>.php -- one
 * class per revision, each extending Migration -- and the thing that finds and
 * runs them is AdminTool::updateDataBaseVersion()
 * (includes/classes/admin_tool.class.php:352-371), which asks
 * Update::getUpdateFiles() for everything between the schema's revision and the
 * checkout's and hands the list to executeTool('execute_migration_file').
 *
 * In the product that is driven from the admin area: a banner appears, an
 * administrator clicks it, admin_area/actions/update_launch.php writes a
 * throwaway CLI script into files/temp and backgrounds it. On a hosting
 * account there is nobody to click it, and an application whose code is newer
 * than its schema is not something to serve while waiting -- every
 * Update::IsCurrentDBVersionIsHigherOrEqualTo() call in the application is
 * gated on that row, and actions/file_uploader.php:7 refuses uploads outright
 * until it is current.
 *
 * So the same tool is run here, synchronously, on the upgrade stage, before
 * Apache binds. Nothing is reimplemented: this is initByCode() and the tool's
 * own function, which is what tool_launch.php does with `id_tool=`.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    die('Forbidden');
}

ini_set('display_errors', 'stderr');

$app = '/app/upload';

// AdminTool writes into files/temp and logs into the tool history; it needs a
// bootstrapped application. $in_bg_cron is what tells config.inc.php not to
// apply the "site closed" and "login required" redirects to a process that is
// not a request -- the same flag admin_area/actions/tool_launch.php sets for
// its own CLI path (tool_launch.php:4-6).
define('THIS_PAGE', 'panelalpha_migrate');
$in_bg_cron = true;
chdir($app);
require_once $app . '/includes/config.inc.php';

$update = Update::getInstance();
$files  = $update->getUpdateFiles();
fwrite(STDERR, '[clipbucket] ' . count($files) . " migration file(s) to run\n");

$tool = new AdminTool();
if ($tool->initByCode(AdminTool::CODE_UPDATE_DATABASE_VERSION) === false) {
    // The tool row is created by a migration of its own (5.5.0/M00367), so an
    // account whose schema predates it has no tool to run. That is not a state
    // this recipe can produce -- it installs at the checkout's own revision --
    // but say so rather than failing with "class_error_occured".
    fwrite(STDERR, "[clipbucket] no `update_database_version` tool in this database\n");
    exit(1);
}

$tool->setToolInProgress();
$tool->launch();

$update->flush();
fwrite(
    STDERR,
    '[clipbucket] schema is now ' . $update->getCurrentDBVersion()
    . '.' . $update->getCurrentDBRevision() . "\n"
);
exit(0);
