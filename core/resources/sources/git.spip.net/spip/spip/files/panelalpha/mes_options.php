<?php
// PanelAlpha mes_options.php for SPIP. Loaded from SPIP_ETC_DIR
// (/pa-data/spip/config) on every hit -- spip-setup.sh copies it there.
//
// Its only job is to pre-fill SPIP's web installer with the account MySQL
// credentials, via SPIP's documented host-preconfiguration constants
// (_INSTALL_*), so the owner never types a DB password: the wizard's database
// step is answered from the environment the engine already injected
// (database: mysql -> DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD).
//
// These constants are read only by the installer (ecrire/install/etape_*).
// Once config/connect.php exists they are inert, so this file is harmless
// post-install. It lives outside the document root and is never served.

if (!defined('_ECRIRE_INC_VERSION')) {
	// still fine to define the install constants when included as pure config
}

$pa_host = getenv('DB_HOST');
if ($pa_host !== false && $pa_host !== '') {
	$pa_port = getenv('DB_PORT');
	if ($pa_port !== false && $pa_port !== '' && $pa_port !== '3306') {
		$pa_host .= ':' . $pa_port;
	}
	if (!defined('_INSTALL_HOST_DB')) {
		define('_INSTALL_HOST_DB', $pa_host);
	}
	if (!defined('_INSTALL_USER_DB')) {
		define('_INSTALL_USER_DB', (string) getenv('DB_USERNAME'));
	}
	if (!defined('_INSTALL_PASS_DB')) {
		define('_INSTALL_PASS_DB', (string) getenv('DB_PASSWORD'));
	}
	if (!defined('_INSTALL_NAME_DB')) {
		define('_INSTALL_NAME_DB', (string) getenv('DB_DATABASE'));
	}
	if (!defined('_INSTALL_SERVER_DB')) {
		define('_INSTALL_SERVER_DB', 'mysql');
	}
	if (!defined('_INSTALL_TABLE_PREFIX')) {
		define('_INSTALL_TABLE_PREFIX', 'spip');
	}
}
