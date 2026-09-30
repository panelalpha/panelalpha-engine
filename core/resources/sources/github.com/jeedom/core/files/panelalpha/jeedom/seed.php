<?php
// Exit 0 once `admin` exists and no longer accepts "admin"; non-zero means retry.
$root = '/var/www/html';
if (!file_exists("$root/core/config/common.config.php") || !file_exists("$root/vendor/autoload.php")) exit(2);
$pw = getenv('JEEDOM_ADMIN_PASSWORD');
if ($pw === false || strlen($pw) < 16) { fwrite(STDERR, "JEEDOM_ADMIN_PASSWORD missing\n"); exit(3); }
try {
    require_once "$root/core/php/core.inc.php";
    $user = user::byLogin('admin');
    if (!is_object($user)) exit(4); // install.php has not created it yet
    $stored = $user->getPassword();
    if (password_verify('admin', $stored) || hash_equals($stored, sha512('admin'))) {
        $user->setPassword($pw);
        $user->save();
        echo "[panelalpha] jeedom: admin password replaced with the engine's one\n";
    }
    $user = user::byLogin('admin');
    if (password_verify('admin', $user->getPassword())) exit(5);
    echo "[panelalpha] jeedom: admin/admin is refused\n";
    exit(0);
} catch (Throwable $e) {
    exit(6);
}
