<?php
/**
 * Plugin Name: PanelAlpha
 * Description: CLI user management and SSO login handler for PanelAlpha app management.
 */
// Only when run as a script: WP-CLI and wp-cron load this file too.
if (PHP_SAPI === 'cli' && (get_included_files()[0] ?? '') === __FILE__) {
    global $argv;
    // For the install action use the provided URL's host so wp_guess_url() returns the
    // right value.  For all other actions fall back to localhost (the real siteurl comes
    // from the DB and is used only when WordPress is already installed).
    if (($argv[1] ?? '') === 'install' && !empty($argv[2])) {
        $_SERVER['HTTP_HOST'] = parse_url($argv[2], PHP_URL_HOST) ?: 'localhost';
    } else {
        $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }
    // WP_INSTALLING suppresses wp_not_installed()'s wp_die() so we can load WordPress
    // regardless of whether the setup wizard has been completed.
    define('WP_INSTALLING', true);
    require_once dirname(__FILE__, 3) . '/wp-load.php';
    if (($argv[1] ?? '') !== 'install' && !is_blog_installed()) {
        fwrite(STDERR, json_encode(['error' => 'WordPress is not installed yet.'], JSON_PRETTY_PRINT));
        exit(1);
    }
    switch ($argv[1] ?? '') {
        case 'users:list':
            $out = [];
            foreach (get_users() as $u) {
                $obj   = new WP_User($u->ID);
                $roles = array_values((array) $obj->roles);
                $out[] = [
                    'id' => (string) $u->ID,
                    'username' => $u->user_login,
                    'email' => $u->user_email,
                    'role' => $roles[0] ?? 'subscriber'
                ];
            }
            echo json_encode($out);
            break;

        case 'users:add': // users:add <login> <email> <password> <role>
            $id = wp_create_user($argv[2], $argv[4], $argv[3]);
            if (is_wp_error($id)) {
                fwrite(STDERR, json_encode(['error' => $id->get_error_message()], JSON_PRETTY_PRINT));
                exit(1);
            }
            (new WP_User($id))->set_role($argv[5] ?? 'subscriber');
            echo json_encode(['id' => (string) $id]);
            break;

        case 'users:delete': // users:delete <user_id>
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user((int) $argv[2]);
            echo json_encode(['success' => true]);
            break;

        case 'users:reset-password': // users:reset-password <user_id> <password>
            wp_set_password($argv[3], (int) $argv[2]);
            echo json_encode(['success' => true]);
            break;

        case 'roles:list':
            $roles = array_keys(wp_roles()->roles);
            echo json_encode($roles);
            break;

        case 'users:sso': // users:sso <user_id>
            $userId = (int)$argv[2];
            $user = get_user_by( 'id', $userId );
            if ( ! $user ) {
                fwrite(STDERR, json_encode(['error' => 'User not found.']) . "\n");
                exit(1);
            }
            $expires = time() + 60;
		    $token   = (string) $expires . '_' . wp_generate_password( 64, false );
            update_user_meta( (int) $userId, 'panelalpha_sso', sha1( $token ) );
            echo json_encode(['url' => rtrim(get_option('siteurl'), '/') . '/?panelalpha_sso=' . $token]);
            break;

        case 'install': // install <url> <title> <admin_user> <admin_email> <admin_password>
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            wp_install($argv[3], $argv[4], $argv[5], true, '', wp_slash($argv[6]));
            // Override whatever siteurl wp_install guessed with the URL we were given.
            update_option('siteurl', rtrim($argv[2], '/'));
            update_option('home', rtrim($argv[2], '/'));
            echo json_encode(['success' => true]);
            break;

        default:
            fwrite(STDERR, json_encode(['error' => 'Unknown action: ' . ($argv[1] ?? '')]) . "\n");
            exit(1);
    }
    exit(0);
}

// Web mode: handle SSO token login on every request.
add_action('init', function () {
    if ( empty( $_REQUEST['panelalpha_sso'] )) {
        return;
    }
    $token = sanitize_text_field( wp_unslash( $_REQUEST['panelalpha_sso'] ) );
    $users = get_users(
        array(
            'meta_key'   => 'panelalpha_sso',
            'meta_value' => sha1( $token ),
            'number'     => 1,
            'fields'     => 'id',
        )
    );
    if ( empty( $users ) ) {
        return;
    }
    $userId = (int) $users[0];
    [$expires]   = explode( '_', $token );
    $expiresTime = (int) $expires;
    if ( time() > $expiresTime ) {
        delete_user_meta( $userId, 'panelalpha_sso' );
        return;
    }
    delete_user_meta( $userId, 'panelalpha_sso' );
    wp_set_current_user($userId);
    wp_set_auth_cookie($userId);
    wp_safe_redirect(user_admin_url());
    exit;
});
