<?php

use App\Http\Controllers\BackupContainerController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\CsfController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\HttpAcmeChallengeController;
use App\Http\Controllers\IpController;
use App\Http\Controllers\LighthouseController;
use App\Http\Controllers\ModsecController;
use App\Http\Controllers\PhpController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\User\CronJobController;
use App\Http\Controllers\User\Domain\LogFileController;
use App\Http\Controllers\User\DomainController as UserDomainController;
use App\Http\Controllers\User\ProjectSettingController;
use App\Http\Controllers\User\TunnelController;
use App\Http\Controllers\User\FileController;
use App\Http\Controllers\User\GitController;
use App\Http\Controllers\User\FtpAccountController;
use App\Http\Controllers\User\Mysql\DatabaseController;
use App\Http\Controllers\User\Mysql\PrivilegesController;
use App\Http\Controllers\User\Mysql\UserController as MysqlUserController;
use App\Http\Controllers\User\MysqlController;
use App\Http\Controllers\User\StagingController;
use App\Http\Controllers\User\PhpController as UserPhpController;
use App\Http\Controllers\User\BackupController;
use App\Http\Controllers\User\AppHealthController;
use App\Http\Controllers\User\AppUserController;
use App\Http\Controllers\User\ContainerController;
use App\Http\Controllers\User\DeployLogController;
use App\Http\Controllers\User\ProxyRuleController;
use App\Http\Controllers\User\UsageController;
use App\Http\Controllers\User\SshController;
use App\Http\Controllers\User\WpCliController;
use App\Http\Controllers\McpTokenController;
use App\Http\Controllers\McpActivityLogController;
use App\Http\Controllers\ServerMetricsController;
use App\Http\Controllers\SecretVaultController;
use App\Http\Controllers\SourceInspectionController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\User\SftpAccountController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\PmaSso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return new Response(null, 204);
});

Route::fallback(function () {
    return new JsonResponse('Not Found', 404);
});

Route::get('/test-connection', function (Request $request) {
    return new JsonResponse(['success' => true]);
});

Route::get('/mcp-tokens', [McpTokenController::class, 'index']);
Route::post('/mcp-tokens', [McpTokenController::class, 'store']);
Route::put('/mcp-tokens/{id}/revoke', [McpTokenController::class, 'revoke']);
Route::delete('/mcp-tokens/{id}', [McpTokenController::class, 'destroy']);

Route::get('/mcp-activity-logs', [McpActivityLogController::class, 'index']);
Route::post('/mcp-activity-logs', [McpActivityLogController::class, 'store']);

/*
 * Secret vault: a secret is pasted into a browser form and then referenced
 * from API calls as `vault:<ref>` in the field that would otherwise carry it
 * (git_token, env_vars values). The secret never passes through the API
 * caller -- see SecretVaultController.
 *
 * `/vault/config` is the other half: whether a project without a credential
 * of its own falls back to the engine's `global` entry of that type.
 */
Route::get('/vault/config', [SecretVaultController::class, 'config']);
Route::put('/vault/config', [SecretVaultController::class, 'updateConfig']);
Route::get('/vault/secrets', [SecretVaultController::class, 'index']);
Route::post('/vault/secrets', [SecretVaultController::class, 'store']);
// `{ref}` is the minted reference, or `global:<type>` for an engine-wide
// secret, whose paste link is rotated and so cannot name it.
Route::get('/vault/secrets/{ref}', [SecretVaultController::class, 'show']);
Route::delete('/vault/secrets/{ref}', [SecretVaultController::class, 'destroy']);

Route::get('/tasks/{id}', [TaskController::class, 'show']);
Route::get('/tasks/{id}/logs', [TaskController::class, 'logs']);
Route::get('/tasks/{id}/logs/stream', [TaskController::class, 'streamLogs']);
Route::post('/tasks/{id}/cancel', [TaskController::class, 'cancel']);

/*
 * Project routes.
 *
 * A "project" is what this API used to call a "user": one hosting account with
 * its own container, domains, databases and files. The resource is now spelled
 * /projects, but /users is still served. Both prefixes are registered from the
 * one closure below, so the canonical name and the legacy alias cannot drift
 * apart -- adding a route here adds it to both.
 *
 * The one deliberate exception is create: POST /projects is async (202 + task),
 * POST /users is the legacy synchronous deploy-in-request. Those two are
 * registered outside the shared closure.
 *
 * The inner "users" segments are a different thing and are NOT renamed:
 * mysql/users are MySQL accounts and app/users are accounts inside the
 * deployed application. They keep their spelling under both prefixes.
 */
$projectRoutes = function (): void {
    Route::get('/', [UserController::class, 'index']);
    Route::get('/all', [UserController::class, 'listAll']);
    Route::post('/verify-new-username', [UserController::class, 'verifyNewUsername']);
    Route::post('/{username}/rebuild', [UserController::class, 'rebuild']);
    Route::post('/{username}/deploy-archive', [UserController::class, 'deployArchive']);
    Route::post('/{username}/clone', [UserController::class, 'clone']);
    Route::post('/{username}/staging', [StagingController::class, 'staging']);
    Route::post('/{username}/push', [StagingController::class, 'push']);
    Route::get('/{username}', [UserController::class, 'show']);
    Route::put('/{username}', [UserController::class, 'update']);
    Route::put('/{username}/suspend', [UserController::class, 'suspend']);
    Route::put('/{username}/unsuspend', [UserController::class, 'unsuspend']);
    Route::delete('/{username}', [UserController::class, 'destroy']);

    Route::get('/{username}/usage', [UsageController::class, 'getUsage']);

    Route::get('/{username}/domains', [UserDomainController::class, 'index']);
    Route::get('/{username}/domains/installed-ssl-certs', [UserDomainController::class, 'indexInstalledSslCerts']);
    Route::get('/{username}/domains/{domain}', [UserDomainController::class, 'show']);
    Route::get('/{username}/domains/{domain}/installed-ssl-cert', [UserDomainController::class, 'installedSslCert']);
    Route::post('/{username}/domains', [UserDomainController::class, 'store']);
    Route::put('/{username}/domains/{domain}', [UserDomainController::class, 'update']);
    Route::delete('/{username}/domains/{domain}', [UserDomainController::class, 'destroy']);

    Route::put('/{username}/domains/{domain}/install-ssl-cert', [UserDomainController::class, 'installSslCert']);
    Route::post('/{username}/domains/{domain}/request-ssl-cert', [UserDomainController::class, 'requestSslCert']);

    // Per-project settings the engine needs and the project cannot carry in
    // its own files -- the Cloudflare API token a cloudflare tunnel requires.
    Route::get('/{username}/settings', [ProjectSettingController::class, 'index']);
    Route::get('/{username}/settings/{key}', [ProjectSettingController::class, 'show']);
    Route::put('/{username}/settings/{key}', [ProjectSettingController::class, 'update']);
    Route::delete('/{username}/settings/{key}', [ProjectSettingController::class, 'destroy']);

    // Public hostnames for a domain, reachable without any DNS of your own.
    Route::get('/{username}/domains/{domain}/tunnels', [TunnelController::class, 'index']);
    Route::post('/{username}/domains/{domain}/tunnels', [TunnelController::class, 'store']);
    Route::delete('/{username}/domains/{domain}/tunnels/{hostname}', [TunnelController::class, 'destroy']);

    Route::get('/{username}/domains/{domain}/log-files', [LogFileController::class, 'index']);
    Route::get('/{username}/domains/{domain}/log-files/{filename}', [LogFileController::class, 'download']);

    Route::get('/{username}/backups', [BackupController::class, 'index']);
    Route::post('/{username}/backups', [BackupController::class, 'store']);
    Route::get('/{username}/backups/{id}', [BackupController::class, 'show']);
    Route::post('/{username}/backups/{id}/restore', [BackupController::class, 'restore']);
    Route::delete('/{username}/backups/{id}', [BackupController::class, 'destroy']);

    Route::get('/{username}/ftp-accounts', [FtpAccountController::class, 'index']);
    Route::post('/{username}/ftp-accounts', [FtpAccountController::class, 'store']);
    Route::put('/{username}/ftp-accounts/{ftpUser}', [FtpAccountController::class, 'update']);
    Route::delete('/{username}/ftp-accounts/{ftpUser}', [FtpAccountController::class, 'destroy']);

    Route::get('/{username}/sftp-accounts', [SftpAccountController::class, 'index']);
    Route::post('/{username}/sftp-accounts', [SftpAccountController::class, 'store']);
    Route::put('/{username}/sftp-accounts/{sftpUser}', [SftpAccountController::class, 'update']);
    Route::delete('/{username}/sftp-accounts/{sftpUser}', [SftpAccountController::class, 'destroy']);

    Route::get('/{username}/mysql/databases', [DatabaseController::class, 'index']);
    Route::get('/{username}/mysql/databases/{dbname}', [DatabaseController::class, 'show']);
    Route::post('/{username}/mysql/databases', [DatabaseController::class, 'store']);
    Route::delete('/{username}/mysql/databases/{dbname}', [DatabaseController::class, 'destroy']);

    Route::get('/{username}/mysql/users', [MysqlUserController::class, 'index']);
    Route::get('/{username}/mysql/users/{dbuser}', [MysqlUserController::class, 'show']);
    Route::post('/{username}/mysql/users', [MysqlUserController::class, 'store']);
    Route::delete('/{username}/mysql/users/{dbuser}', [MysqlUserController::class, 'destroy']);
    Route::put('/{username}/mysql/users/{dbuser}/rename', [MysqlUserController::class, 'rename']);
    Route::put('/{username}/mysql/users/{dbuser}/change-password', [MysqlUserController::class, 'changePassword']);

    Route::get('/{username}/mysql/privileges/{dbuser}/{dbname}', [PrivilegesController::class, 'show']);
    Route::put('/{username}/mysql/privileges/{dbuser}/{dbname}', [PrivilegesController::class, 'update']);
    Route::delete('/{username}/mysql/privileges/{dbuser}/{dbname}', [PrivilegesController::class, 'destroy']);

    Route::get('/{username}/mysql/server-info', [MysqlController::class, 'serverInfo']);

    Route::post('/{username}/mysql/phpmyadmin-sso-token', [MysqlController::class, 'createPhpmyadminSsoToken']);

    Route::get('/{username}/cron-jobs', [CronJobController::class, 'index']);
    Route::post('/{username}/cron-jobs', [CronJobController::class, 'store']);
    Route::delete('/{username}/cron-jobs/{hash}', [CronJobController::class, 'destroy']);
    Route::put('/{username}/cron-jobs/{hash}', [CronJobController::class, 'update']);

    Route::get('/{username}/files/exists', [FileController::class, 'exists']);
    Route::get('/{username}/files/stat', [FileController::class, 'stat']);
    Route::post('/{username}/files/upload', [FileController::class, 'upload']);
    Route::get('/{username}/files/download', [FileController::class, 'download']);
    Route::delete('/{username}/files/remove', [FileController::class, 'remove']);
    Route::post('/{username}/files/mkdir', [FileController::class, 'mkdir']);
    Route::post('/{username}/files/zip', [FileController::class, 'zip']);
    Route::post('/{username}/files/unzip', [FileController::class, 'unzip']);
    Route::put('/{username}/files/mv', [FileController::class, 'mv']);
    Route::put('/{username}/files/cp', [FileController::class, 'cp']);
    Route::put('/{username}/files/put-contents', [FileController::class, 'putContents']);

    Route::get('/{username}/git/status', [GitController::class, 'status']);
    Route::get('/{username}/git/branches', [GitController::class, 'branches']);
    Route::get('/{username}/git/commits', [GitController::class, 'commits']);
    Route::post('/{username}/git/connect', [GitController::class, 'connect']);
    Route::post('/{username}/git/disconnect', [GitController::class, 'disconnect']);
    Route::put('/{username}/git/change-branch', [GitController::class, 'changeBranch']);
    Route::put('/{username}/git/update-credentials', [GitController::class, 'updateCredentials']);
    Route::post('/{username}/git/pull', [GitController::class, 'pull']);
    Route::post('/{username}/git/push', [GitController::class, 'push']);
    Route::post('/{username}/git/revert', [GitController::class, 'revert']);

    Route::post('/{username}/wp-cli/command', [WpCliController::class, 'run']);

    Route::post('/{username}/ssh/command', [SshController::class, 'run']);

    Route::get('/{username}/php/custom-ini-settings', [UserPhpController::class, 'listCustomIniSettings']);
    Route::put('/{username}/php/custom-ini-settings', [UserPhpController::class, 'updateCustomIniSettings']);

    Route::get('/{username}/containers', [ContainerController::class, 'index']);
    Route::post('/{username}/containers/action', [ContainerController::class, 'projectAction']);
    Route::post('/{username}/containers/{service}/action', [ContainerController::class, 'serviceAction']);
    Route::get('/{username}/containers/{service}/logs', [ContainerController::class, 'logs']);

    // What the account's own files are, next to what its last deploy decided.
    // The same report POST /source/inspect gives for a repository or a path,
    // so both spellings of the question are one word: inspect.
    Route::get('/{username}/inspect', [SourceInspectionController::class, 'project']);

    // Deprecated spelling of the line above, kept for one release so existing
    // integrations do not break on a rename. Deliberately undocumented: an
    // alias in the OpenAPI document would generate a second MCP tool for the
    // one operation, and clients would have two names for one answer.
    Route::get('/{username}/source-inspection', [SourceInspectionController::class, 'project']);

    Route::get('/{username}/deploy-log', [DeployLogController::class, 'show']);
    Route::post('/{username}/deploy-cancel', [DeployLogController::class, 'cancel']);

    Route::get('/{username}/app/users', [AppUserController::class, 'index']);
    Route::post('/{username}/app/users', [AppUserController::class, 'store']);
    Route::delete('/{username}/app/users/{userId}', [AppUserController::class, 'destroy']);
    Route::put('/{username}/app/users/{userId}/password', [AppUserController::class, 'resetPassword']);
    Route::post('/{username}/app/users/{userId}/sso', [AppUserController::class, 'createSsoToken']);
    // Unauthenticated: the token in the query string is the capability. The
    // throttle is the brute-force floor the api group does not provide -- it
    // has `throttle:api` commented out, so without this the route answers as
    // fast as a client can ask.
    Route::get('/{username}/app/sso-token', [AppUserController::class, 'useAppSsoToken'])
        ->withoutMiddleware('auth:api')
        ->middleware('throttle:60,1');
    Route::get('/{username}/app/health', [AppHealthController::class, 'show']);
    Route::get('/{username}/app/info', [AppUserController::class, 'info']);
    Route::get('/{username}/app/roles', [AppUserController::class, 'roles']);
    Route::post('/{username}/app/install', [AppUserController::class, 'install']);
};

// Create is the one path that deliberately diverges: /projects is async,
// /users keeps the legacy synchronous deploy-in-request behaviour.
Route::post('/projects', [UserController::class, 'storeAsync']);
Route::post('/users', [UserController::class, 'store']);

Route::prefix('projects')->group($projectRoutes);

// Legacy alias. Documented as deprecated, but not going away: existing
// integrations address the engine by this path.
Route::prefix('users')->group($projectRoutes);

Route::get('/backup-containers', [BackupContainerController::class, 'index']);
Route::post('/backup-containers', [BackupContainerController::class, 'store']);
Route::get('/backup-containers/{id}', [BackupContainerController::class, 'show']);
Route::put('/backup-containers/{id}', [BackupContainerController::class, 'update']);
Route::delete('/backup-containers/{id}', [BackupContainerController::class, 'destroy']);
Route::post('/backup-containers/{id}/test', [BackupContainerController::class, 'test']);

// Same shape, and this one hands back MySQL credentials, so it gets the same
// floor. PmaSso is a second gate, not the only one: it reads the client address,
// and an address is only as good as the proxy chain that produced it.
Route::put('/mysql/phpmyadmin-sso-token', [MysqlController::class, 'usePhpmyadminSsoToken'])
    ->withoutMiddleware('auth:api')
    ->middleware([PmaSso::class, 'throttle:60,1']);

Route::get('/proxy-rules', [ProxyRuleController::class, 'index']);
Route::get('/proxy-rules/{id}', [ProxyRuleController::class, 'show']);
Route::post('/proxy-rules', [ProxyRuleController::class, 'store']);
Route::put('/proxy-rules/{id}', [ProxyRuleController::class, 'update']);
Route::delete('/proxy-rules/{id}', [ProxyRuleController::class, 'destroy']);

// Reads an application without deploying it: a repository URL, a directory on
// this server, or an existing project's files.
Route::post('/source/inspect', [SourceInspectionController::class, 'inspect']);

Route::get('/php/available-versions', [PhpController::class, 'listAvailableVersions']);
Route::get('/domains/{domain}/php-version', [DomainController::class, 'getPhpVersion']);
Route::put('/domains/{domain}/php-version', [DomainController::class, 'setPhpVersion']);

Route::get('/domains/{domain}/http-acme-challenges', [HttpAcmeChallengeController::class, 'index']);
Route::post('/domains/{domain}/http-acme-challenges', [HttpAcmeChallengeController::class, 'store']);
Route::delete('/domains/{domain}/http-acme-challenges', [HttpAcmeChallengeController::class, 'destroyAll']);
Route::get('/domains/{domain}/http-acme-challenges/{token}', [HttpAcmeChallengeController::class, 'show'])
    ->where('token', '[A-Za-z0-9_-]+');
Route::delete('/domains/{domain}/http-acme-challenges/{token}', [HttpAcmeChallengeController::class, 'destroy'])
    ->where('token', '[A-Za-z0-9_-]+');

Route::get('/domains/{domain}', [DomainController::class, 'show']);
// Files a bug against the engine itself, through the telemetry channel the
// install events already use. Not under /system: it is not a setting on this
// box, it is a message leaving it.
Route::post('/bug-reports', [BugReportController::class, 'store']);

Route::get('/system/info', [SystemController::class, 'info']);
Route::get('/system/ssl-config', [SystemController::class, 'getSslConfig']);
Route::put('/system/ssl-config', [SystemController::class, 'updateSslConfig']);
Route::put('/system/engine-certificate', [SystemController::class, 'requestEngineCertificate']);
Route::put('/system/network-config', [SystemController::class, 'updateNetworkConfig']);
Route::put('/system/update', [SystemController::class, 'update']);
Route::put('/system/change-webserver', [SystemController::class, 'changeWebserver']);
Route::put('/system/reset-webserver-panel-password', [SystemController::class, 'resetWebserverPanelPassword']);
Route::put('/system/webserver-config', [SystemController::class, 'updateWebserverConfig']);
Route::get('/system/exim-config', [SystemController::class, 'getEximConfig']);
Route::put('/system/exim-config', [SystemController::class, 'updateEximConfig']);
Route::post('/system/exim-send-test-email', [SystemController::class, 'sendTestEmail']);

Route::get('/system/ipv4-nat-maps', [SystemController::class, 'listIpv4NatMaps']);
Route::put('/system/ipv4-nat-maps', [SystemController::class, 'upsertIpv4NatMap']);
Route::delete('/system/ipv4-nat-maps/{id}', [SystemController::class, 'deleteIpv4NatMap']);
Route::post('/system/ipv4-nat-maps/rebuild', [SystemController::class, 'rebuildIpv4NatMaps']);

Route::get('/csf/rules', [CsfController::class, 'getRules']);
Route::post('/csf/rules/{type}', [CsfController::class, 'addRule']);
Route::put('/csf/rules/{type}/{lineMd5}', [CsfController::class, 'editRule']);
Route::delete('/csf/rules/{type}/{lineMd5}', [CsfController::class, 'deleteRule']);
Route::get('/csf/ui-credentials', [CsfController::class, 'getUiCredentials']);
Route::get('/csf/status', [CsfController::class, 'getStatus']);
Route::put('/csf/restart', [CsfController::class, 'restart']);
Route::put('/csf/enable', [CsfController::class, 'enable']);
Route::put('/csf/disable', [CsfController::class, 'disable']);

Route::get('/metrics/current', [ServerMetricsController::class, 'current']);
Route::get('/metrics/last-5-minutes', [ServerMetricsController::class, 'last5Minutes']);
Route::get('/metrics/last-hour', [ServerMetricsController::class, 'lastHour']);
Route::get('/metrics/last-12-hours', [ServerMetricsController::class, 'last12Hours']);
Route::get('/metrics/last-hour-averages', [ServerMetricsController::class, 'lastHourAverages']);

Route::get('/ip/subnets', [IpController::class, 'listSubnets']);
Route::post('/ip/subnets', [IpController::class, 'addSubnet']);
Route::delete('/ip/subnets/{id}', [IpController::class, 'deleteSubnet']);
Route::get('/ip/assigned', [IpController::class, 'listAssignedIps']);
Route::post('/ip/assign', [IpController::class, 'assignIpAddress']);
Route::post('/ip/unassign', [IpController::class, 'unassignIpAddress']);

Route::get('/modsec/mode', [ModsecController::class, 'getMode']);
Route::put('/modsec/mode', [ModsecController::class, 'setMode']);
Route::get('/modsec/rulesets', [ModsecController::class, 'getRulesets']);
Route::put('/modsec/rulesets/{name}/enable', [ModsecController::class, 'enableRuleset']);
Route::put('/modsec/rulesets/{name}/disable', [ModsecController::class, 'disableRuleset']);
Route::put('/modsec/rulesets/{name}/config-files', [ModsecController::class, 'toggleConfigFiles']);
Route::get('/modsec/audit-log/files', [ModsecController::class, 'listAuditLogFiles']);
Route::get('/modsec/audit-log/files/{filename}', [ModsecController::class, 'downloadAuditLogFile']);
Route::get('/modsec/audit-log/files/{filename}/tail', [ModsecController::class, 'tailAuditLogFile']);

Route::post('/lighthouse/generate-report', [LighthouseController::class, 'generateReport']);
