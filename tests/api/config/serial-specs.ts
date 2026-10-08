/**
 * Specs that must run alone when the suite runs in parallel.
 *
 * With `TEST_PARALLEL` on, `api-parallel` runs the rest of the API suite on several
 * workers, each with its own setup user. Everything listed here goes to `api-serial`,
 * which runs afterwards on one worker with nothing else going on. A spec belongs here
 * when it changes state the other workers can see (the webserver, the firewall, ModSecurity,
 * mail, IP management, system settings, global lists) or when it reloads the webserver or
 * rebuilds a container while its own checks depend on timing. When in doubt a spec is
 * listed: moving it out later is a measured decision, moving it in after a flake is not.
 *
 * Globs are relative to `tests/`, without the `.spec.ts` suffix. `*` matches within one
 * path segment.
 */
export interface SerialSpec {
  glob: string;
  reason: string;
}

export const SERIAL_SPECS: readonly SerialSpec[] = [
  { glob: 'firewall/*', reason: 'host firewall rules and ports, seen by every worker' },
  {
    glob: 'modsec/*',
    reason: 'global ModSecurity mode, rulesets and the WAF in front of every site',
  },
  { glob: 'exim/*', reason: 'engine mail configuration' },
  { glob: 'system/webserver*', reason: 'webserver info and change; reloads serve every project' },
  { glob: 'system/ssl-config*', reason: 'engine-wide certificate issuer settings' },
  { glob: 'system/network-config*', reason: 'engine-wide network settings' },
  { glob: 'system/ipv4-nat*', reason: 'engine-wide NAT map' },
  { glob: 'ip/ipam-*', reason: 'fixed subnets; unassigning frees addresses of every project' },
  {
    glob: 'proxy-rules/*',
    reason: 'system-owned proxy rules rebuild and reload the proxy in the background',
  },
  { glob: 'cli/settings', reason: 'writes a fixed global setting key (ssl_issuer)' },
  { glob: 'php/php-ini', reason: 'changes the shared user php.ini and ignores failed restores' },
  { glob: 'php/php-versions', reason: 'switches PHP on the shared user; rebuilds and reloads' },
  { glob: 'php/php-workers', reason: 'limits the shared user PHP pool to one process' },
  { glob: 'domains/domains-global', reason: 'global domain lists and settings' },
  {
    glob: 'domains/domains-crud',
    reason: 'adds and removes domains on the shared user; vhost reloads',
  },
  {
    glob: 'domains/domains-ssl',
    reason: 'issues and removes certificates on the shared user; reloads',
  },
  {
    glob: 'domains/domains-request-ssl',
    reason: 'certificate requests on the shared user; reloads',
  },
  {
    glob: 'domains/domains-normalization',
    reason: 'creates a domain on the shared user; vhost reload',
  },
  { glob: 'domains/domains-logs', reason: 'creates a domain on the shared user; vhost reload' },
  { glob: 'lscache/*', reason: 'LiteSpeed cache config, plugin and purge on the shared user site' },
  {
    glob: 'acme/*',
    reason: 'writes HTTP-01 challenges the webserver serves for shared user domains',
  },
  {
    glob: 'integration/htaccess',
    reason: 'rewrites the shared user docroot rules; webserver reload',
  },
  {
    glob: 'integration/www-redirect',
    reason: 'redirect settings on the shared user domain; reload',
  },
  {
    glob: 'integration/ioncube',
    reason: 'PHP extension on the shared user; restore errors are ignored',
  },
  {
    glob: 'integration/webserver-templates',
    reason: 'webserver template output for the shared user; reload',
  },
  { glob: 'integration/real-ip', reason: 'webserver real-IP config on the shared user; reload' },
  { glob: 'integration/ssl-https-redirect', reason: 'redirect and certificate config; reload' },
  {
    glob: 'integration/uid-ownership*',
    reason: 'UID allocation and ownership transfer across projects',
  },
  {
    glob: 'permalinks/*',
    reason: 'rewrites WordPress permalinks and rules of the shared user site',
  },
  { glob: 'wp-cli/wp-cli-lifecycles', reason: 'WordPress lifecycle on the shared user site' },
  { glob: 'wp-cli/wp-soap', reason: 'rebuilds the shared user container' },
  {
    glob: 'wp-cli/wp-private-ip-block',
    reason: 'depends on engine network rules for private addresses',
  },
  { glob: 'users/users-list', reason: 'compares global project counts across two reads' },
  {
    glob: 'users/users-vhost-cleanup',
    reason: 'checks the shared site keeps serving after another project is removed',
  },
  { glob: 'backup/backup-containers', reason: 'global backup container list' },
  { glob: 'mcp/mcp-tokens', reason: 'global token list' },
  { glob: 'mcp/mcp-activity-logs', reason: 'global activity log' },
  { glob: 'vault/vault-secrets', reason: 'global secret list' },
];

/**
 * The API suite: every spec the `api` project runs. Moved here from the Playwright config
 * so the split can be checked against it. Those projects have their own run.
 */
export const API_SUITE_IGNORE: readonly RegExp[] = [
  /tests\/unit\//,
  /tests\/cli\//,
  /tests\/deploy\//,
  /system\/webserver-change\.spec\.ts/,
  /system\/update\//,
  /system\/engine-certificate\.spec\.ts/,
  /system\/network-config-mutation\.spec\.ts/,
];

/** Regular expression for one glob, matched against a path ending in `tests/<glob>.spec.ts`. */
export function serialGlobToRegExp(glob: string): RegExp {
  const body = glob
    .split('*')
    .map((part) => part.replace(/[.+?^${}()|[\]\\/]/g, '\\$&'))
    .join('[^/]*');
  return new RegExp(`(^|[\\\\/])tests[\\\\/]${body}\\.spec\\.ts$`);
}

export const SERIAL_SPEC_MATCHERS: readonly RegExp[] = SERIAL_SPECS.map((entry) =>
  serialGlobToRegExp(entry.glob)
);

export function isSerialSpec(file: string): boolean {
  const normalised = file.replace(/\\/g, '/');
  return SERIAL_SPEC_MATCHERS.some((matcher) => matcher.test(normalised));
}
