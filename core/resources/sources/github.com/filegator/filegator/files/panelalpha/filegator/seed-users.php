<?php
// Seeds the generated admin password into FileGator's users.json (JsonFile
// auth adapter). Runs before the app starts; exits non-zero to block it.
$file = '/var/www/filegator/private/users.json';
$hash = trim((string) @file_get_contents('/run/panelalpha/admin.hash'));
if (strpos($hash, '$2y$') !== 0) {
    fwrite(STDERR, "admin hash missing in ~/.panelalpha/filegator/admin.hash\n");
    exit(1);
}

$exists = is_file($file);
$users = json_decode((string) file_get_contents($exists ? $file : $file . '.blank'), true);
if (!is_array($users)) {
    // Never replace a users file we cannot read.
    fwrite(STDERR, "$file is not valid JSON; leaving it alone\n");
    exit(1);
}

$changed = !$exists;
foreach ($users as &$u) {
    // The shipped admin: default password admin123, or none at all.
    if (($u['username'] ?? '') === 'admin'
        && (($u['password'] ?? '') === '' || password_verify('admin123', $u['password']))) {
        $u['password'] = $hash;
        $changed = true;
    }
}
unset($u);

if ($changed) {
    file_put_contents($file, json_encode($users), LOCK_EX);
    chown($file, 'www-data');
    chgrp($file, 'www-data');
    chmod($file, 0640);
    echo "seeded the admin password\n";
}
