<?php
// Helper of glpi-login.sh, on GLPI's database (the GLPI_DB_* the image reads).
//   php glpi-default-logins.php                  prints the built-in accounts that are
//                                                active and still have their default password
//   php glpi-default-logins.php rename OLD NEW   renames the account OLD to NEW
// The list and the check are GLPI's own User::checkDefaultPasswords(); columns
// used: glpi_users.name, password, is_active, is_deleted.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli(
    getenv('GLPI_DB_HOST'),
    getenv('GLPI_DB_USER'),
    getenv('GLPI_DB_PASSWORD'),
    getenv('GLPI_DB_NAME'),
    (int) (getenv('GLPI_DB_PORT') ?: 3306)
);

if (($argv[1] ?? '') === 'rename') {
    [, , $old, $new] = $argv;
    if ($old === $new) {
        exit(0);
    }
    $taken = $db->execute_query('SELECT id FROM glpi_users WHERE name = ?', [$new])->fetch_row();
    if ($taken) {
        fwrite(STDERR, "[panelalpha] glpi: a user named $new already exists; the login stays $old\n");
        exit(1);
    }
    $db->execute_query('UPDATE glpi_users SET name = ? WHERE name = ?', [$new, $old]);
    exit(0);
}

$defaults = ['glpi' => 'glpi', 'tech' => 'tech', 'normal' => 'normal', 'post-only' => 'postonly'];
$rows = $db->execute_query(
    "SELECT name, password FROM glpi_users WHERE is_active = 1 AND is_deleted = 0 AND name IN ('glpi', 'tech', 'normal', 'post-only')"
);
foreach ($rows as $row) {
    $hash = (string) $row['password'];
    $plain = $defaults[strtolower($row['name'])];
    // Auth::checkPassword() is password_verify().
    if (password_verify($plain, $hash)) {
        echo $row['name'], "\n";
    }
}
