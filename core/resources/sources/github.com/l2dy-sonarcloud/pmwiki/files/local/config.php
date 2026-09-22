<?php if (!defined('PmWiki')) exit();
##  PanelAlpha local/config.php for PmWiki. No secret lives in this file (the
##  admin hash is read from ~/.panelalpha at runtime), and local/.htaccess
##  denies it over HTTP anyway. pmwiki-setup.sh prepares /pa-data before boot.

$WikiTitle = 'PmWiki';
$Skin = 'pmwiki-responsive';

##  Unicode wiki (recommended for new wikis).
$DefaultCharset = 'UTF-8';

##  URLs. Behind the engine's http proxy PmWiki would self-detect the
##  container's own host:port (http://...:8000); pin everything to the public
##  https origin instead. $ScriptUrl with no trailing script works because
##  index.php is the DirectoryIndex: PmWiki emits "$ScriptUrl?n=Group.Page".
$pa_url = rtrim((string)getenv('APP_URL'), '/');
if ($pa_url !== '') {
  $ScriptUrl   = $pa_url;
  $PubDirUrl   = "$pa_url/pub";
  $UploadUrlFmt = "$pa_url/uploads";
}

##  Admin password. The bcrypt hash is generated once by pmwiki-setup.sh into
##  ~/.panelalpha/pmwiki/admin.hash (0600) and read here. Setting ['edit'] to
##  the same hash closes anonymous editing: reading stays public, editing and
##  uploading require the admin login. If the hash file is missing we leave the
##  distribution default (locked attr passwords), never an open wiki.
$pa_hash = @file_get_contents('/pa-data/pmwiki/admin.hash');
if (is_string($pa_hash)) {
  $pa_hash = trim($pa_hash);
  if ($pa_hash !== '') {
    $DefaultPasswords['admin'] = $pa_hash;
    $DefaultPasswords['edit']  = $pa_hash;
  }
}

##  Attachments. uploads/ is symlinked to ~/.panelalpha so it survives a
##  redeploy; the default $UploadExts whitelist already excludes executable
##  types, and uploads/.htaccess (written by the setup script) disables PHP.
$EnableUpload = 1;
$UploadDir = 'uploads';
$UploadMaxSize = 20000000; # 20 MB, matches the php.ini limits
