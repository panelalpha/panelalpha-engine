/**
 * Which tests a pull request runs: the map from changed paths to spec groups.
 *
 * `bun run test:pr` runs typecheck, lint, the unit project, the `@smoke` tests, the specs this
 * map names for the changed paths, and a few supported apps. Every changed path is looked up
 * here (see scripts/lib/pr-selection.ts); the answer is the union of what all the matching
 * rules name, each spec once. A path nothing matches, or one a rule marks `full`, widens the
 * run to the whole API project and the Supported-apps checks, so a gap in this map costs
 * time, never coverage.
 *
 * Engine paths are relative to the engine repository root (the suite lives in `tests/api`).
 * A pattern starting with `@suite/` is relative to the suite instead. `*` stays within one path
 * segment, `**` crosses segments. Spec globs are relative to `tests/`, without `.spec.ts`, and
 * use the syntax of config/serial-specs.ts. A glob that names a spec outside the `api` project
 * (cli, deploy, system/update, ...) selects nothing: those run in `test:nightly` or on demand.
 */

export interface PrRule {
  id: string;
  reason: string;
  /** Changed paths this rule applies to. */
  paths: readonly string[];
  /** Specs to run when one of the paths changed. */
  specs?: readonly string[];
  /** The change widens to the full API project and all Supported-apps checks. */
  full?: boolean;
  /** Run `tests/deploy/supported-apps-checks.spec.ts` (staging, backup, hook, front page). */
  checks?: boolean;
}

/** One app per build family; short Memos/FreshRSS runs, Koel for Laravel, Umami for Node.js. */
export const PR_SUPPORTED_APPS: readonly string[] = ['freshrss', 'koel', 'umami', 'memos'];

/** Spec files that always belong to the PR run when any API spec runs. */
export const SETUP_SPEC = 'tests/setup/test-user.setup.ts';
export const CATALOGUE_SPEC = 'tests/deploy/supported-apps.spec.ts';
export const CHECKS_SPEC = 'tests/deploy/supported-apps-checks.spec.ts';

/** Paths that never need a test run; the unit project, typecheck and lint cover the rest. */
export const PR_IGNORED_PATHS: readonly string[] = [
  'docs/**',
  '**/*.md',
  'LICENSE',
  'tests/unit/**',
  '@suite/tests/unit/**',
  '@suite/scripts/**',
  '@suite/eslint*',
  '@suite/.prettier*',
  '@suite/.gitignore',
  '@suite/package*.json',
  '@suite/tsconfig.json',
  '@suite/env/**',
  '@suite/test-data/static/supported-apps.ts',
];

/** Engine code that every spec depends on: a change there runs the whole API project. */
export const PR_SHARED_RULES: readonly PrRule[] = [
  {
    id: 'shared-core',
    reason: 'shared engine code (Support, config, routes, auth, middleware, models, migrations)',
    full: true,
    checks: true,
    paths: [
      'core/app/Support/**',
      'core/config/**',
      'core/routes/**',
      'core/composer.*',
      'core/bootstrap/**',
      'core/database/**',
      'core/app/Auth/**',
      'core/app/Http/Kernel.php',
      'core/app/Http/Middleware/**',
      'core/app/Http/Requests/**',
      'core/app/Http/Resources/**',
      'core/app/Http/Controllers/Controller.php',
      'core/app/Models/**',
      'core/app/Providers/**',
      'core/app/Exceptions/**',
      'core/app/Rules/**',
      'core/app/OpenApi/**',
      'core/app/Lib/Helper.php',
      'core/app/Lib/Helpers/**',
      'core/app/Lib/Traits/**',
      'core/app/System.php',
      'core/app/System/Filesystem.php',
      'core/app/System/HostProcess.php',
      'core/app/System/ProcessRunner.php',
      'core/app/System/EnginePaths.php',
    ],
  },
  {
    id: 'shared-packaging',
    reason: 'images, compose files, webserver templates and installers',
    full: true,
    checks: true,
    paths: [
      'dockerfiles/**',
      'templates/**',
      'docker-compose.yml*',
      'nginx.conf',
      'nginx-proxy-conf.d/**',
      'apache2-core.conf',
      'config/**',
      'installer.sh',
      'updater.sh',
      'webserver.sh',
      'uninstall.sh',
      'scripts/**',
    ],
  },
  {
    id: 'shared-suite',
    reason: 'suite plumbing every spec uses (helpers, fixtures, clients, config, setup, reporters)',
    full: true,
    paths: [
      '@suite/helpers/**',
      '@suite/fixtures/**',
      '@suite/clients/**',
      '@suite/assertions/**',
      '@suite/schemas/**',
      '@suite/types/**',
      '@suite/config/**',
      '@suite/reporters/**',
      '@suite/eslint-rules/**',
      '@suite/test-data/**',
      '@suite/playwright.config.ts',
      '@suite/tests/setup/**',
    ],
  },
  {
    id: 'suite-outside-pr',
    reason: 'specs of the cli, webserver-change, update, engine-cert and network-mutation projects',
    paths: [
      '@suite/tests/cli/**',
      '@suite/tests/system/update/**',
      '@suite/tests/system/webserver-change.spec.ts',
      '@suite/tests/system/engine-certificate.spec.ts',
      '@suite/tests/system/network-config-mutation.spec.ts',
    ],
  },
  {
    id: 'suite-catalogue',
    reason: 'the Supported-apps specs; the catalogue already runs with the PR apps',
    checks: true,
    paths: [
      '@suite/tests/deploy/supported-apps-checks.spec.ts',
      '@suite/tests/deploy/supported-apps.spec.ts',
    ],
  },
];

const PROJECT_CODE = [
  'core/app/Lib/Project/**',
  'core/app/System/Project.php',
  'core/app/System/Projects.php',
  'core/app/System/UsernamePolicy.php',
  'core/app/System/AccountContainers.php',
  'core/app/System/ComposeProject.php',
  'core/app/Console/Commands/ProjectFleetCommand.php',
  'core/app/Console/Commands/Users/**',
];

/** Engine code to the specs that exercise it. One rule per route family of core/routes/api.php. */
export const PR_RULES: readonly PrRule[] = [
  {
    id: 'domains',
    reason: 'project domains, aliases, log files, vhosts',
    paths: [
      'core/app/Http/Controllers/User/DomainController.php',
      'core/app/Http/Controllers/User/Domain/**',
      'core/app/Lib/Domains/**',
      'core/app/System/Project/Domain.php',
      'core/app/Console/Commands/Domains/**',
      'core/app/Console/Commands/System/RebuildDomains.php',
      'core/app/Console/Commands/System/CleanupDisconnectedDomains.php',
      'core/app/Console/Commands/System/CreateExampleDomain.php',
      'core/app/Console/Commands/System/DeleteExampleDomain.php',
      'core/app/Console/Commands/System/FallbackDomain.php',
    ],
    specs: ['domains/*', 'integration/www-redirect'],
  },
  {
    id: 'ssl',
    reason: 'certificate issuing, installing and the engine issuer settings',
    paths: ['core/app/Lib/Ssl/**', 'core/app/Console/Commands/Ssl/**'],
    specs: [
      'domains/domains-ssl*',
      'domains/domains-request-ssl',
      'system/ssl-config*',
      'system/engine-tls',
      'acme/*',
      'integration/ssl-https-redirect',
    ],
  },
  {
    id: 'acme',
    reason: 'HTTP-01 challenge store and endpoint',
    paths: [
      'core/app/Http/Controllers/HttpAcmeChallengeController.php',
      'core/app/Lib/HttpAcmeChallengeStore.php',
      'core/app/Console/Commands/System/PruneHttpAcmeChallenges.php',
    ],
    specs: ['acme/*'],
  },
  {
    id: 'mysql',
    reason: 'MySQL databases, users, privileges, phpMyAdmin SSO',
    paths: [
      'core/app/Http/Controllers/User/Mysql/**',
      'core/app/Http/Controllers/User/MysqlController.php',
      'core/app/System/Services/Mysql.php',
      'core/app/System/Services/Mysql/**',
      'core/app/Console/Commands/System/TestDatabaseConnection.php',
    ],
    specs: ['mysql/*'],
  },
  {
    id: 'backup',
    reason: 'project backups, restores, backup containers and storage',
    paths: [
      'core/app/Http/Controllers/User/BackupController.php',
      'core/app/Http/Controllers/BackupContainerController.php',
      'core/app/Jobs/CreateBackup.php',
      'core/app/Jobs/RestoreBackup.php',
      'core/app/Jobs/DeleteBackup.php',
      'core/app/Jobs/DeleteBackupContainer.php',
      'core/app/System/Project/Backup.php',
      'core/app/System/Project/HomeMerge.php',
      'core/app/Integrations/Storage/**',
      'core/app/Console/Commands/Backup/**',
    ],
    specs: ['backup/*'],
    checks: true,
  },
  {
    id: 'cron',
    reason: 'cron jobs',
    paths: [
      'core/app/Http/Controllers/User/CronJobController.php',
      'core/app/System/Project/Cron.php',
    ],
    specs: ['cron/*'],
  },
  {
    id: 'ftp',
    reason: 'FTP accounts',
    paths: [
      'core/app/Http/Controllers/User/FtpAccountController.php',
      'core/app/System/Project/Ftp.php',
      'core/app/System/Services/PureFtpd.php',
    ],
    specs: ['ftp/*'],
  },
  {
    id: 'sftp',
    reason: 'SFTP accounts and the SFTP service',
    paths: [
      'core/app/Http/Controllers/User/SftpAccountController.php',
      'core/app/System/Project/Sftp.php',
      'core/app/System/Services/Sftp.php',
      'core/app/Console/Commands/System/RebuildSftpAccounts.php',
    ],
    specs: ['sftp/*'],
  },
  {
    id: 'ssh',
    reason: 'project shell commands',
    paths: [
      'core/app/Http/Controllers/User/SshController.php',
      'core/app/Lib/Project/ProjectShell.php',
      'core/app/Console/Commands/Users/ProjectSshCommand.php',
    ],
    specs: ['ssh/*'],
  },
  {
    id: 'wp-cli',
    reason: 'WP-CLI endpoint and WordPress provisioning',
    paths: [
      'core/app/Http/Controllers/User/WpCliController.php',
      'core/app/Console/Commands/Domains/WpCli.php',
    ],
    specs: ['wp-cli/*', 'permalinks/*'],
  },
  {
    id: 'files',
    reason: 'file manager API',
    paths: [
      'core/app/Http/Controllers/User/FileController.php',
      'core/app/System/Project/FileManager.php',
      'core/app/Lib/Project/ProjectFiles.php',
      'core/app/Console/Commands/Files/**',
      'core/app/Console/Commands/Users/FixFilePermissions.php',
    ],
    specs: ['files/*'],
  },
  {
    id: 'git',
    reason: 'git checkout, deploy keys and deploy hooks',
    paths: [
      'core/app/Http/Controllers/User/GitController.php',
      'core/app/Http/Controllers/User/DeployHookController.php',
      'core/app/Http/Controllers/Web/HookController.php',
      'core/app/Lib/Git/**',
      'core/app/Lib/DeployHook/**',
      'core/app/Jobs/RunHookDelivery.php',
      'core/app/System/Project/Git.php',
      'core/app/System/Project/Git/**',
      'core/app/Console/Commands/Git/**',
    ],
    specs: ['git/*'],
    checks: true,
  },
  {
    id: 'containers',
    reason: 'project containers, logs and the deploy log',
    paths: [
      'core/app/Http/Controllers/User/ContainerController.php',
      'core/app/Http/Controllers/User/DeployLogController.php',
      'core/app/Lib/Deploy/DeployLog/**',
      'core/app/Console/Commands/Deploy/DeployLogs*.php',
    ],
    specs: ['containers/*'],
  },
  {
    id: 'project-settings',
    reason: 'per-project settings and the site password gate',
    paths: [
      'core/app/Http/Controllers/User/ProjectSettingController.php',
      'core/app/Http/Controllers/User/ProjectPasswordController.php',
      'core/app/Http/Controllers/SitePasswordGateController.php',
      'core/app/System/Project/Settings.php',
      'core/app/System/Project/SitePasswordProtection.php',
      'core/app/Console/Commands/Users/ProjectSettings/**',
    ],
    specs: ['project-settings/*'],
  },
  {
    id: 'tunnels',
    reason: 'domain tunnels',
    paths: [
      'core/app/Http/Controllers/User/TunnelController.php',
      'core/app/Integrations/Tunnels/**',
      'core/app/Lib/Apis/Cloudflare*',
      'core/app/Lib/Apis/Cloudflare/**',
      'core/app/Lib/Apis/PanelAlpha*',
      'core/app/Lib/Apis/PanelAlpha/**',
    ],
    specs: ['tunnels/*'],
  },
  {
    id: 'usage',
    reason: 'usage, bandwidth, visitor statistics',
    paths: [
      'core/app/Http/Controllers/User/UsageController.php',
      'core/app/Lib/Usage/**',
      'core/app/Lib/Limits/**',
      'core/app/Integrations/Statistics/**',
      'core/app/Integrations/GeoLocation/**',
      'core/app/Console/Commands/Usage/**',
      'core/app/Console/Commands/Stats/**',
      'core/app/Console/Commands/Geolocation/**',
    ],
    specs: ['users/usage-*', 'users/users-limits'],
  },
  {
    id: 'projects',
    reason: 'project lifecycle: create, clone, staging, suspend, delete, domain change',
    paths: [
      'core/app/Http/Controllers/UserController.php',
      'core/app/Http/Controllers/User/StagingController.php',
      'core/app/Jobs/CreateStaging.php',
      'core/app/Jobs/PushState.php',
      'core/app/System/Project/Dind.php',
      'core/app/System/Project/Dind/**',
      ...PROJECT_CODE,
    ],
    specs: [
      'users/*',
      'health/projects-alias',
      'integration/cross-user-isolation',
      'integration/delete-idempotency',
      'integration/uid-ownership*',
    ],
    checks: true,
  },
  {
    id: 'deploy',
    reason: 'deploy pipeline: detection, compose, rebuild, archives',
    paths: [
      'core/app/Jobs/DeployProject.php',
      'core/app/Jobs/RebuildProject.php',
      'core/app/Jobs/Concerns/**',
      'core/app/Lib/Deploy/**',
      'core/app/System/Project/Deployment/**',
      'core/app/System/Project/AbstractApplication.php',
      'core/app/System/Project/Runtime.php',
      'core/app/Console/Commands/Deploy/**',
    ],
    specs: [
      'users/users-lifecycle',
      'users/users-create-async',
      'users/users-clone',
      'containers/*',
      'git/git-deploy-managed',
      'app/*',
      'inspect/*',
      'env-vars/image-only-compose',
    ],
    checks: true,
  },
  {
    id: 'env-vars',
    reason: 'project env vars and the overrides a deploy applies from them',
    paths: [
      'core/app/Http/Controllers/User/EnvVarsController.php',
      'core/app/Lib/Deploy/EnvVarOverrides.php',
    ],
    specs: ['env-vars/*'],
  },
  {
    id: 'inspect',
    reason: 'source inspection',
    paths: [
      'core/app/Http/Controllers/SourceInspectionController.php',
      'core/app/Lib/Deploy/Inspect/**',
    ],
    specs: ['inspect/*'],
  },
  {
    id: 'app',
    reason: 'app users, credentials and health inside the project',
    paths: [
      'core/app/Http/Controllers/User/AppUserController.php',
      'core/app/Http/Controllers/User/AppCredentialsController.php',
      'core/app/Http/Controllers/User/AppHealthController.php',
      'core/app/Lib/Deploy/Health/**',
    ],
    specs: ['app/*'],
  },
  {
    id: 'php',
    reason: 'PHP versions, ini settings, workers',
    paths: [
      'core/app/Http/Controllers/PhpController.php',
      'core/app/Http/Controllers/User/PhpController.php',
      'core/app/System/Services/Php.php',
      'core/app/System/Project/Php.php',
      'core/app/System/Project/PhpHosting.php',
      'core/app/System/Project/PhpHosting/**',
      'core/app/Lib/Domains/DomainPhpVersion.php',
      'core/app/Lib/Project/CustomIniSettings.php',
      'core/app/Console/Commands/Projects/ProjectPhpDirectives*.php',
      'core/app/Console/Commands/Concerns/PrintsPhpSettings.php',
    ],
    specs: ['php/*', 'integration/ioncube'],
  },
  {
    id: 'modsec',
    reason: 'ModSecurity mode, rulesets, audit log, WAF',
    paths: [
      'core/app/Http/Controllers/ModsecController.php',
      'core/app/Lib/Modsec/**',
      'core/app/System/Services/Modsec.php',
      'core/app/Console/Commands/System/RebuildModsecurityConfig.php',
      'core/app/Console/Commands/Files/ModsecAuditLogCommand.php',
      'core/**/ModSec*',
    ],
    specs: ['modsec/*'],
  },
  {
    id: 'firewall',
    reason: 'host firewall rules and logs',
    paths: ['core/app/Http/Controllers/FirewallController.php', 'core/app/System/Firewall/**'],
    specs: ['firewall/*'],
  },
  {
    id: 'exim',
    reason: 'engine mail configuration',
    paths: [
      'core/app/System/Services/Exim.php',
      'core/app/Lib/Mail/**',
      'core/app/Console/Commands/System/RebuildEximConfig.php',
    ],
    specs: ['exim/*'],
  },
  {
    id: 'ip',
    reason: 'IP management and the IPv4 NAT map',
    paths: [
      'core/app/Http/Controllers/IpController.php',
      'core/app/Lib/Project/ProjectIpAddresses.php',
      'core/app/Console/Commands/System/SyncIpsToInterface.php',
      'core/app/Console/Commands/System/BuildIpv4NatMap.php',
      'core/app/System/Network.php',
    ],
    specs: ['ip/*', 'system/ipv4-nat*', 'system/network-config*'],
  },
  {
    id: 'lighthouse',
    reason: 'Lighthouse runs',
    paths: ['core/app/Http/Controllers/LighthouseController.php', 'core/app/Lib/Lighthouse/**'],
    specs: ['lighthouse/*'],
  },
  {
    id: 'webserver',
    reason: 'webserver service, vhost templates, LiteSpeed cache',
    paths: [
      'core/app/System/Services/Webserver.php',
      'core/app/System/Services/Webserver/**',
      'core/app/Console/Commands/System/UpdateWebserverConfig.php',
      'core/app/Console/Commands/System/Lscache.php',
      'core/app/Console/Commands/Apache/**',
    ],
    specs: [
      'system/webserver*',
      'integration/htaccess',
      'integration/real-ip',
      'integration/webserver-templates',
      'integration/ssl-https-redirect',
      'integration/www-redirect',
      'lscache/*',
      'permalinks/*',
    ],
  },
  {
    id: 'system',
    reason: 'system info, metrics, monitoring',
    paths: [
      'core/app/Http/Controllers/SystemController.php',
      'core/app/Http/Controllers/ServerMetricsController.php',
      'core/app/Lib/Host/**',
      'core/app/Integrations/Monitoring/**',
      'core/app/System/EngineUpdate.php',
      'core/app/Console/Commands/System/PruneMetrics.php',
      'core/app/Console/Commands/System/VersionCommand.php',
      'core/app/Console/Commands/System/GuardHostDisk.php',
    ],
    specs: ['system/system-info', 'system/metrics', 'health/*'],
  },
  {
    id: 'api-access',
    reason: 'the Engine API token an app holds for its own project',
    paths: [
      'core/app/Http/Controllers/SystemApiAccessController.php',
      'core/app/Lib/Project/ApiAccess.php',
      'core/app/Lib/Project/ApiAccessFirewall.php',
      'core/app/Console/Commands/System/ApiAccess*.php',
    ],
    specs: ['system/api-access'],
  },
  {
    id: 'mcp',
    reason: 'MCP server, tools, tokens, activity logs',
    paths: [
      'core/app/Mcp/**',
      'core/app/Http/Controllers/McpTokenController.php',
      'core/app/Http/Controllers/McpActivityLogController.php',
      'core/app/Console/Commands/Mcp/**',
      'core/app/Console/Commands/Api/**',
    ],
    specs: ['mcp/*'],
  },
  {
    id: 'vault',
    reason: 'secret vault',
    paths: [
      'core/app/Http/Controllers/SecretVaultController.php',
      'core/app/Http/Controllers/Web/VaultFormController.php',
      'core/app/Lib/Vault/**',
      'core/app/Console/Commands/Vault/**',
    ],
    specs: ['vault/*'],
  },
  {
    id: 'tasks',
    reason: 'background task records and logs',
    paths: [
      'core/app/Http/Controllers/TaskController.php',
      'core/app/Lib/Task/**',
      'core/app/Console/Commands/Task/**',
    ],
    specs: ['tasks/*'],
  },
  {
    id: 'bug-reports',
    reason: 'bug reports and telemetry',
    paths: [
      'core/app/Http/Controllers/BugReportController.php',
      'core/app/Console/Commands/Telemetry/**',
      'core/app/Lib/Deploy/Telemetry/**',
    ],
    specs: ['bug-reports/*'],
  },
  {
    id: 'proxy-rules',
    reason: 'proxy rules',
    paths: [
      'core/app/Http/Controllers/User/ProxyRuleController.php',
      'core/app/Console/Commands/System/ProxyRule*.php',
      'core/app/Console/Commands/Concerns/AppliesProxyRules.php',
    ],
    specs: ['proxy-rules/*'],
  },
  {
    id: 'cli-only',
    reason: 'CLI-only commands; specs run in the cli project (nightly)',
    paths: [
      'core/app/Console/Commands/ConfigureCommand.php',
      'core/app/Console/Commands/Settings/**',
      'core/app/Console/Commands/DockerHub/**',
      'core/app/Console/Commands/Testing/**',
      'core/app/Console/Commands/System/InspireCommand.php',
      'core/app/Console/Commands/System/PrewarmImages.php',
      'core/app/Console/Commands/System/PruneHostImages.php',
      'core/app/Console/Commands/System/QueueWorkersSync.php',
      'core/app/Console/Commands/System/TrimRegistryProxy.php',
    ],
    specs: ['cli/*'],
  },
];
