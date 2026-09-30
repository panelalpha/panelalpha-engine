<?php
//Default Configuration
$CONFIG = '{"lang":"en","error_reporting":false,"show_hidden":false,"hide_Cols":false,"theme":"light"}';
// PanelAlpha overrides for tinyfilemanager.php, mounted read-only as config.php
// next to it (the first three lines keep the layout FM_Config::save() expects).

// One admin, bcrypt hash of the engine's password written by hooks/prepare.sh.
// Fail closed: an empty $auth_users would switch authentication off.
$pa_hash = @file_get_contents('/etc/tinyfm/admin.hash');
$pa_hash = is_string($pa_hash) ? trim($pa_hash) : '';
if (strpos($pa_hash, '$2y$') !== 0) {
    http_response_code(503);
    exit('Tiny File Manager: the admin credentials are not configured.');
}
$use_auth = true;
$auth_users = array('admin' => $pa_hash);
$readonly_users = array();
$global_readonly = false;
$directories_users = array();
unset($pa_hash);

// Managed files live on the `files` volume, outside the web root, so Apache
// never serves them and an uploaded .php is never executed.
$root_path = '/srv/files';
$root_url = '';

// The Google viewer fetches a file's direct URL, which does not exist here.
$online_viewer = false;
