<?php

// The MCP tool name for every documented API operation, keyed by "VERB /path".
//
// These names are written out by hand rather than derived from the URL, because
// they are the client-facing contract: an assistant picks a tool by its name, and
// a name that changes because someone reshuffled a route breaks saved prompts and
// tool allow-lists. Deriving them also produced the shape this file exists to get
// rid of -- get_users_by_username_mysql_databases_by_dbname, which spells out the
// parameters the caller is going to pass anyway.
//
// The convention is <resource>_<action>, resource singular:
//
//   project_suspend        not  update_users_by_username_suspend
//   mysql_database_get     not  get_users_by_username_mysql_databases_by_dbname
//
// Actions settle on a small vocabulary so the same idea reads the same way
// everywhere: list, get, create, update, delete -- plus the operation's own verb
// where the endpoint does something specific (suspend, clone, rename, install).
// Parameters never appear in a name.
//
// `php artisan mcp:tool:generate` reads this file and fails loudly on an
// operation that is missing from it or on an entry that no longer matches a
// route, so adding an endpoint is a deliberate naming decision rather than an
// accident. See docs/mcp-catalogue.html.

return [
    // Projects -- one hosting account with its container, domains, databases and
    // files. This is the resource the API used to call a "user".
    'GET /projects' => 'project_list',
    'POST /projects' => 'project_create',
    // The legacy synchronous spelling of create: same body as POST /projects,
    // but the deploy runs inside the request (and can stream as ndjson) and
    // the resource comes back instead of a task. Documented on purpose, so a
    // client that cannot poll tasks has a create -- and named, because an
    // operation without a name blocks generation of everything else. A
    // project by its other name would trip the naming test, so it is
    // `project_create_sync`: one more word for the one real difference.
    'POST /users' => 'project_create_sync',
    'GET /projects/all' => 'project_list_all',
    'POST /projects/verify-new-username' => 'project_verify_name',
    'GET /projects/{username}' => 'project_get',
    'PUT /projects/{username}' => 'project_update',
    'DELETE /projects/{username}' => 'project_delete',
    'POST /projects/{username}/clone' => 'project_clone',
    'POST /projects/{username}/staging' => 'project_staging',
    'POST /projects/{username}/push' => 'project_push',
    'POST /projects/{username}/deploy-archive' => 'project_deploy_archive',
    'POST /projects/{username}/rebuild' => 'project_rebuild',
    'PUT /projects/{username}/suspend' => 'project_suspend',
    'PUT /projects/{username}/unsuspend' => 'project_unsuspend',
    'GET /projects/{username}/usage' => 'project_usage',
    'GET /projects/{username}/bandwidth' => 'project_bandwidth',
    'GET /projects/{username}/domains/{domain}/bandwidth' => 'domain_bandwidth',
    'GET /projects/{username}/domains/{domain}/visitors' => 'domain_visitors',
    'GET /projects/{username}/domains/{domain}/visitors/{dimension}' => 'domain_visitors_breakdown',

    // Deploy
    'POST /projects/{username}/deploy-cancel' => 'deploy_cancel',
    'GET /projects/{username}/deploy-log' => 'deploy_log_get',
    // Not tied to a project: it reads a repository, a path or an account's
    // files and reports what would be deployed.
    'POST /source/inspect' => 'source_inspect',
    // The same report for a project that already has files, plus the snapshot
    // its last deploy froze and where the two now disagree. One verb for both
    // questions: source_inspect takes a repository, project_inspect takes an
    // account, and they answer in the same fields.
    'GET /projects/{username}/inspect' => 'project_inspect',

    // Domains
    'GET /projects/{username}/domains' => 'domain_list',
    'POST /projects/{username}/domains' => 'domain_create',
    'GET /projects/{username}/domains/{domain}' => 'domain_get',
    'PUT /projects/{username}/domains/{domain}' => 'domain_update',
    'DELETE /projects/{username}/domains/{domain}' => 'domain_delete',

    // Project settings -- values the engine holds for a project, such as the
    // Cloudflare API token a cloudflare tunnel needs. Secrets read back
    // redacted.
    'GET /projects/{username}/settings' => 'project_setting_list',
    'GET /projects/{username}/settings/{key}' => 'project_setting_get',
    'PUT /projects/{username}/settings/{key}' => 'project_setting_set',
    'DELETE /projects/{username}/settings/{key}' => 'project_setting_delete',

    // Tunnels -- a public hostname that reaches a domain with no DNS record of
    // the operator's own.
    'GET /projects/{username}/domains/{domain}/tunnels' => 'tunnel_list',
    'POST /projects/{username}/domains/{domain}/tunnels' => 'tunnel_create',
    'DELETE /projects/{username}/domains/{domain}/tunnels/{hostname}' => 'tunnel_delete',
    // Looks a domain up server-wide, without knowing which project owns it --
    // hence "find" rather than "get".
    'GET /domains/{domain}' => 'domain_find',
    'GET /domains/{domain}/php-version' => 'domain_php_version_get',
    'PUT /domains/{domain}/php-version' => 'domain_php_version_set',
    'GET /domains/{domain}/php-directives' => 'domain_php_directives_get',
    'PUT /domains/{domain}/php-directives' => 'domain_php_directives_set',
    'GET /projects/{username}/domains/{domain}/log-files' => 'domain_log_list',
    'GET /projects/{username}/domains/{domain}/log-files/{filename}' => 'domain_log_download',

    // ACME HTTP-01 challenges
    'GET /domains/{domain}/http-acme-challenges' => 'acme_challenge_list',
    'POST /domains/{domain}/http-acme-challenges' => 'acme_challenge_create',
    'DELETE /domains/{domain}/http-acme-challenges' => 'acme_challenge_delete_all',
    'GET /domains/{domain}/http-acme-challenges/{token}' => 'acme_challenge_get',
    'DELETE /domains/{domain}/http-acme-challenges/{token}' => 'acme_challenge_delete',

    // SSL certificates
    'GET /projects/{username}/domains/installed-ssl-certs' => 'ssl_cert_list',
    'GET /projects/{username}/domains/{domain}/installed-ssl-cert' => 'ssl_cert_get',
    'PUT /projects/{username}/domains/{domain}/install-ssl-cert' => 'ssl_cert_install',
    'POST /projects/{username}/domains/{domain}/request-ssl-cert' => 'ssl_cert_request',

    // FTP and SFTP accounts
    'GET /projects/{username}/ftp-accounts' => 'ftp_account_list',
    'POST /projects/{username}/ftp-accounts' => 'ftp_account_create',
    'PUT /projects/{username}/ftp-accounts/{ftpUser}' => 'ftp_account_update',
    'DELETE /projects/{username}/ftp-accounts/{ftpUser}' => 'ftp_account_delete',
    'GET /projects/{username}/sftp-accounts' => 'sftp_account_list',
    'POST /projects/{username}/sftp-accounts' => 'sftp_account_create',
    'PUT /projects/{username}/sftp-accounts/{sftpUser}' => 'sftp_account_update',
    'DELETE /projects/{username}/sftp-accounts/{sftpUser}' => 'sftp_account_delete',

    // MySQL. These "users" are MySQL accounts, not projects.
    'GET /projects/{username}/mysql/databases' => 'mysql_database_list',
    'POST /projects/{username}/mysql/databases' => 'mysql_database_create',
    'GET /projects/{username}/mysql/databases/{dbname}' => 'mysql_database_get',
    'DELETE /projects/{username}/mysql/databases/{dbname}' => 'mysql_database_delete',
    'GET /projects/{username}/mysql/users' => 'mysql_user_list',
    'POST /projects/{username}/mysql/users' => 'mysql_user_create',
    'GET /projects/{username}/mysql/users/{dbuser}' => 'mysql_user_get',
    'DELETE /projects/{username}/mysql/users/{dbuser}' => 'mysql_user_delete',
    'PUT /projects/{username}/mysql/users/{dbuser}/rename' => 'mysql_user_rename',
    'PUT /projects/{username}/mysql/users/{dbuser}/change-password' => 'mysql_user_change_password',
    'GET /projects/{username}/mysql/privileges/{dbuser}/{dbname}' => 'mysql_privileges_get',
    'PUT /projects/{username}/mysql/privileges/{dbuser}/{dbname}' => 'mysql_privileges_set',
    'DELETE /projects/{username}/mysql/privileges/{dbuser}/{dbname}' => 'mysql_privileges_revoke',
    'GET /projects/{username}/mysql/server-info' => 'mysql_server_info',
    'POST /projects/{username}/mysql/phpmyadmin-sso-token' => 'phpmyadmin_sso_token_create',
    'PUT /mysql/phpmyadmin-sso-token' => 'phpmyadmin_sso_login',

    // Cron jobs
    'GET /projects/{username}/cron-jobs' => 'cron_job_list',
    'POST /projects/{username}/cron-jobs' => 'cron_job_create',
    'PUT /projects/{username}/cron-jobs/{hash}' => 'cron_job_update',
    'DELETE /projects/{username}/cron-jobs/{hash}' => 'cron_job_delete',

    // Files. mv/cp/put-contents are named for what they do, not for the shell
    // command the route borrowed its spelling from.
    'GET /projects/{username}/files/exists' => 'file_exists',
    'GET /projects/{username}/files/stat' => 'file_stat',
    'GET /projects/{username}/files/download' => 'file_download',
    'POST /projects/{username}/files/upload' => 'file_upload',
    'DELETE /projects/{username}/files/remove' => 'file_delete',
    'POST /projects/{username}/files/mkdir' => 'file_mkdir',
    'POST /projects/{username}/files/zip' => 'file_zip',
    'POST /projects/{username}/files/unzip' => 'file_unzip',
    'PUT /projects/{username}/files/mv' => 'file_move',
    'PUT /projects/{username}/files/cp' => 'file_copy',
    'PUT /projects/{username}/files/put-contents' => 'file_write',

    // Git. Site-directory porcelain on a project path. Not the clone that
    // happened at deploy time — those accounts are managed_by=deploy and
    // mutate through project_rebuild.
    'GET /projects/{username}/git/status' => 'git_status',
    'GET /projects/{username}/git/branches' => 'git_branches',
    'GET /projects/{username}/git/commits' => 'git_commits',
    'POST /projects/{username}/git/connect' => 'git_connect',
    'POST /projects/{username}/git/disconnect' => 'git_disconnect',
    'PUT /projects/{username}/git/change-branch' => 'git_change_branch',
    'PUT /projects/{username}/git/update-credentials' => 'git_update_credentials',
    'POST /projects/{username}/git/pull' => 'git_pull',
    'POST /projects/{username}/git/push' => 'git_push',
    'POST /projects/{username}/git/revert' => 'git_revert',

    // WP-CLI
    'POST /projects/{username}/wp-cli/command' => 'wp_cli_run',

    // Shell access to a project's container: one command per call, run as the
    // project user. Dind projects only.
    'POST /projects/{username}/ssh/command' => 'ssh_run',

    // PHP
    'GET /php/available-versions' => 'php_version_list',
    'GET /projects/{username}/php/custom-ini-settings' => 'php_ini_get',
    'PUT /projects/{username}/php/custom-ini-settings' => 'php_ini_set',

    // Containers. The project action drives every service in the project's
    // compose stack; the service action drives one of them.
    'GET /projects/{username}/containers' => 'container_list',
    'POST /projects/{username}/containers/action' => 'container_project_action',
    'POST /projects/{username}/containers/{service}/action' => 'container_service_action',
    'GET /projects/{username}/containers/{service}/logs' => 'container_service_logs',

    // The deployed application (WordPress and friends). These "users" are
    // accounts inside that application, not projects.
    'GET /projects/{username}/app/health' => 'app_health_check',
    'GET /projects/{username}/app/info' => 'app_info',
    'POST /projects/{username}/app/install' => 'app_install',
    'GET /projects/{username}/app/roles' => 'app_role_list',
    'GET /projects/{username}/app/sso-token' => 'app_sso_login',
    'GET /projects/{username}/app/users' => 'app_user_list',
    'POST /projects/{username}/app/users' => 'app_user_create',
    'DELETE /projects/{username}/app/users/{userId}' => 'app_user_delete',
    'PUT /projects/{username}/app/users/{userId}/password' => 'app_user_reset_password',
    'POST /projects/{username}/app/users/{userId}/sso' => 'app_user_sso_create',

    // Reverse proxy rules
    'GET /proxy-rules' => 'proxy_rule_list',
    'POST /proxy-rules' => 'proxy_rule_create',
    'GET /proxy-rules/{id}' => 'proxy_rule_get',
    'PUT /proxy-rules/{id}' => 'proxy_rule_update',
    'DELETE /proxy-rules/{id}' => 'proxy_rule_delete',

    // Secret Vault
    // A secret pasted into a browser form, then referenced from API calls as
    // `vault:<ref>` in the field that would otherwise carry it (git_token,
    // env_vars values). The whole point is that the secret never passes
    // through the agent's conversation: `create` hands back a `vault:<ref>`
    // plus a form URL, the customer pastes there, and the next call with the
    // ref gets the plaintext. `status` is what an agent polls to wait for the
    // paste; `delete` is cleanup. The secret itself is never returned by any
    // of these.
    //
    // `create` with `scope: global` stores the engine's own secret of a type
    // instead -- pasted once, used by every project that has none of its own,
    // so an agent stops asking for the same Git token at every project. The
    // `config` pair is the switch that governs whether projects inherit it.
    'POST /vault/secrets' => 'vault_secret_create',
    'GET /vault/secrets' => 'vault_secret_list',
    'GET /vault/secrets/{ref}' => 'vault_secret_status',
    'DELETE /vault/secrets/{ref}' => 'vault_secret_delete',
    'GET /vault/config' => 'vault_config_get',
    'PUT /vault/config' => 'vault_config_set',

    // System
    // Files a bug against the engine over the telemetry channel. `create`
    // rather than `send`: it is queued here and shipped by the scheduler, and
    // a name promising delivery would be a name that lies.
    'POST /bug-reports' => 'bug_report_create',

    'GET /system/info' => 'system_info',
    'PUT /system/update' => 'system_update',
    'PUT /system/change-webserver' => 'system_webserver_change',
    'PUT /system/webserver-config' => 'system_webserver_config_set',
    'PUT /system/reset-webserver-panel-password' => 'system_webserver_password_reset',
    'GET /system/ssl-config' => 'system_ssl_config_get',
    'PUT /system/ssl-config' => 'system_ssl_config_set',
    'PUT /system/engine-certificate' => 'system_engine_cert_request',
    'GET /system/exim-config' => 'system_exim_config_get',
    'PUT /system/exim-config' => 'system_exim_config_set',
    'POST /system/exim-send-test-email' => 'system_test_email_send',

    // Server metrics
    'GET /metrics/current' => 'metrics_current',
    'GET /metrics/last-5-minutes' => 'metrics_last_5_minutes',
    'GET /metrics/last-hour' => 'metrics_last_hour',
    'GET /metrics/last-12-hours' => 'metrics_last_12_hours',
    'GET /metrics/last-hour-averages' => 'metrics_last_hour_averages',

    // CSF firewall
    'GET /csf/rules' => 'csf_rule_list',
    'POST /csf/rules/{type}' => 'csf_rule_create',
    'PUT /csf/rules/{type}/{lineMd5}' => 'csf_rule_update',
    'DELETE /csf/rules/{type}/{lineMd5}' => 'csf_rule_delete',
    'GET /csf/status' => 'csf_status',
    'GET /csf/ui-credentials' => 'csf_ui_credentials',
    'PUT /csf/restart' => 'csf_restart',
    'PUT /csf/enable' => 'csf_enable',
    'PUT /csf/disable' => 'csf_disable',

    // IP management
    'GET /ip/subnets' => 'ip_subnet_list',
    'POST /ip/subnets' => 'ip_subnet_create',
    'DELETE /ip/subnets/{id}' => 'ip_subnet_delete',
    'GET /ip/assigned' => 'ip_assigned_list',
    'POST /ip/assign' => 'ip_assign',
    'POST /ip/unassign' => 'ip_unassign',

    // ModSecurity
    'GET /modsec/mode' => 'modsec_mode_get',
    'PUT /modsec/mode' => 'modsec_mode_set',
    'GET /modsec/rulesets' => 'modsec_ruleset_list',
    'PUT /modsec/rulesets/{name}/enable' => 'modsec_ruleset_enable',
    'PUT /modsec/rulesets/{name}/disable' => 'modsec_ruleset_disable',
    'PUT /modsec/rulesets/{name}/config-files' => 'modsec_ruleset_configs_set',
    'GET /modsec/audit-log/files' => 'modsec_audit_log_list',
    'GET /modsec/audit-log/files/{filename}' => 'modsec_audit_log_download',
    'GET /modsec/audit-log/files/{filename}/tail' => 'modsec_audit_log_tail',

    // Lighthouse
    'POST /lighthouse/generate-report' => 'lighthouse_report_create',

    // Tasks
    'GET /tasks/{id}' => 'task_get',
    'GET /tasks/{id}/logs' => 'task_log_list',
    'GET /tasks/{id}/logs/stream' => 'task_log_stream',
    'POST /tasks/{id}/cancel' => 'task_cancel',

    // Backup containers
    'GET /backup-containers' => 'backup_container_list',
    'POST /backup-containers' => 'backup_container_create',
    'GET /backup-containers/{id}' => 'backup_container_get',
    'PUT /backup-containers/{id}' => 'backup_container_update',
    'DELETE /backup-containers/{id}' => 'backup_container_delete',
    'POST /backup-containers/{id}/test' => 'backup_container_test',

    // Project backups
    'GET /projects/{username}/backups' => 'backup_list',
    'POST /projects/{username}/backups' => 'backup_create',
    'GET /projects/{username}/backups/{id}' => 'backup_get',
    'POST /projects/{username}/backups/{id}/restore' => 'backup_restore',
    'DELETE /projects/{username}/backups/{id}' => 'backup_delete',
];
