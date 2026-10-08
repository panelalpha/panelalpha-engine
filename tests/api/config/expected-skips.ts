/**
 * The skip reasons this suite accepts. A skip whose reason matches none of these
 * is UNEXPECTED: a precondition broke, a fixture is gone, or a test started
 * skipping for a reason nobody reviewed. See reporters/skip-classification.ts.
 *
 * Add a reason here only when skipping is the right behaviour for it, and anchor
 * `match` on the stable part of the text so a reworded reason shows up as unexpected.
 */
export type SkipCategory = 'environment' | 'opt-in' | 'product-decision' | 'known-issue';

export interface ExpectedSkip {
  id: string;
  match: RegExp;
  category: SkipCategory;
  why: string;
  /** More skips than this for one reason is treated like an unexpected skip. */
  max?: number;
}

export const SKIP_CATEGORIES: readonly SkipCategory[] = [
  'environment',
  'opt-in',
  'product-decision',
  'known-issue',
];

export const EXPECTED_SKIPS: readonly ExpectedSkip[] = [
  // Environment: the engine runs nginx-proxy, or lacks an optional component.
  {
    id: 'lscache-needs-litespeed',
    match: /^LiteSpeed Cache needs LiteSpeed or OpenLiteSpeed;/,
    category: 'environment',
    why: 'The LiteSpeed cache plugin cannot work behind nginx-proxy.',
  },
  {
    id: 'htaccess-needs-apache',
    match: /^nginx does not support \.htaccess files/,
    category: 'environment',
    why: '.htaccess only exists on Apache and LiteSpeed.',
  },
  {
    id: 'nginx-internal-error-pages',
    match: /^nginx serves its error pages internally/,
    category: 'environment',
    why: 'nginx answers 403/500/502 from its own pages, which cannot be fetched directly.',
  },
  {
    id: 'standalone-nginx-only',
    match: /^This test applies to standalone nginx; got /,
    category: 'environment',
    why: 'Only meaningful when the engine runs plain nginx.',
  },
  {
    id: 'acme-vhost-nginx-only',
    match: /^The ACME vhost exception applies to nginx and nginx-proxy only;/,
    category: 'environment',
    why: 'Checks an nginx vhost template.',
  },
  {
    id: 'https-redirect-nginx-proxy-only',
    match: /^force_https_redirect is implemented for nginx-proxy only;/,
    category: 'environment',
    why: 'Other webservers do not implement the redirect flag.',
  },
  {
    id: 'www-redirect-nginx-proxy-only',
    match: /^The apex-to-www vhost redirect is wired for nginx-proxy only;/,
    category: 'environment',
    why: 'Other webservers do not wire the apex-to-www redirect.',
  },
  {
    id: 'www-label-not-registered',
    match: /^www is not registered under panelalpha\.online;/,
    category: 'environment',
    why: 'The proxy serves only the exact PanelAlpha Online label.',
  },
  {
    id: 'webserver-switch-locked',
    match: /^This engine refuses every webserver switch:/,
    category: 'environment',
    why: 'Switching away from nginx-proxy is disabled while DinD projects need it.',
  },
  {
    id: 'litespeed-enterprise-only',
    match: /^This test only applies on LiteSpeed Enterprise\./,
    category: 'environment',
    why: 'Needs the LiteSpeed Enterprise webserver.',
  },
  {
    id: 'litespeed-panel-only',
    match: /^Only LiteSpeed-family stacks have a control panel\./,
    category: 'environment',
    why: 'Needs a LiteSpeed-family webserver.',
  },
  {
    id: 'litespeed-serial-enterprise-only',
    match: /^A serial number only applies to LiteSpeed Enterprise, not OpenLiteSpeed\./,
    category: 'environment',
    why: 'Needs the LiteSpeed Enterprise webserver.',
  },
  {
    id: 'firewall-logs-over-loopback',
    match:
      /is this runner's own address: the knock goes over lo, which ufw accepts without logging\./,
    category: 'environment',
    why: 'Run the suite from another machine to exercise firewall logging.',
  },
  {
    id: 'firewall-unavailable',
    match: /^The host firewall \(ufw\) is not installed or cannot be read\./,
    category: 'environment',
    why: 'ufw is optional on the host.',
  },
  {
    id: 'real-ip-on-engine-host',
    match: /^Suite is running on the engine host; PHP sees Docker gateway /,
    category: 'environment',
    why: 'Real-IP restoration needs a client outside the host.',
  },
  {
    id: 'private-ip-not-private-here',
    match: /^The in-container request reached the site as REMOTE_ADDR /,
    category: 'environment',
    why: 'The probe ran and reached PHP, but the address it arrived from is not private on this stack.',
    max: 1,
  },
  {
    id: 'dind-unavailable',
    match: /^DinD is not available on this engine\./,
    category: 'environment',
    why: 'The engine does not offer Docker-in-Docker projects.',
  },
  {
    id: 'dind-container-management',
    match:
      /^Container management is (?:only available for DinD users|not available for the created user)/,
    category: 'environment',
    why: 'The engine does not offer Docker-in-Docker projects.',
  },
  {
    id: 'git-deploy-refused',
    match: /^Could not create a git-deployed /,
    category: 'environment',
    why: 'The engine refused the git-deployed template explicitly.',
  },
  {
    id: 'no-setup-state',
    match: /^No (?:DinD )?setup state — run /,
    category: 'environment',
    why: 'Precondition: the setup project has not run for this base URL.',
  },
  {
    id: 'lighthouse-disabled',
    match: /^The Lighthouse service is disabled on this engine\./,
    category: 'environment',
    why: 'Lighthouse is an optional service.',
  },
  {
    id: 'phpmyadmin-not-served',
    match: /^phpMyAdmin is not served on this engine\./,
    category: 'environment',
    why: 'phpMyAdmin is an optional component.',
  },
  {
    id: 'panelalpha-online-not-allocated',
    match: /^PanelAlpha Online was not allocated \(/,
    category: 'environment',
    why: 'The engine has no PanelAlpha Online label for this project.',
  },
  {
    id: 'ipam-unavailable',
    match: /^IP management is not available on this engine\./,
    category: 'environment',
    why: 'IP management is an optional module.',
  },
  {
    id: 'proxy-rules-unavailable',
    match: /^Proxy rules are not available on this engine\./,
    category: 'environment',
    why: 'Proxy rules are an optional module.',
  },
  {
    id: 'php-versions-fewer-than-two',
    match: /^The engine offers fewer than two PHP versions\./,
    category: 'environment',
    why: 'Switching PHP versions needs two to choose from.',
  },
  {
    id: 'php-81-missing',
    match: /^This engine offers no PHP 8\.1\./,
    category: 'environment',
    why: 'The ionCube test needs PHP 8.1.',
  },
  {
    id: 'pae-artisan-unreachable',
    match: /^pae-artisan is not reachable from this runner\./,
    category: 'environment',
    why: 'CLI tests need the engine host.',
  },
  {
    id: 'engine-checkout-unreadable',
    match:
      /^(?:core\/app\/Mcp\/tool-names\.php is not readable from this checkout\.|The engine templates directory is not present\.)/,
    category: 'environment',
    why: 'The runner has no engine checkout to compare against.',
  },
  {
    id: 'sftp-symlink-unsupported',
    match: /^This SFTP server does not expose a symlink operation\./,
    category: 'environment',
    why: 'Depends on the SFTP server build.',
  },

  // Opt-in: a destructive or costly test that runs only when asked for.
  {
    id: 'opt-in-network-mutation',
    match: /^Set ALLOW_NETWORK_MUTATION=1 to /,
    category: 'opt-in',
    why: 'Rewrites default_ipv4 / NAT maps and rebuilds every vhost.',
  },
  {
    id: 'opt-in-bug-report-ship',
    match: /^Set ALLOW_BUG_REPORT_SHIP=1 to /,
    category: 'opt-in',
    why: 'A queued bug report reaches production monitoring.',
  },
  {
    id: 'opt-in-wp-security-plugin',
    match: /^Set WP_SECURITY_PLUGIN_TEST=\w+ to run this\./,
    category: 'opt-in',
    why: 'Installs a third-party security plugin.',
  },
  {
    id: 'opt-in-pae-register',
    match: /^Register mutates \/usr\/local\/bin; set ALLOW_PAE_REGISTER=1/,
    category: 'opt-in',
    why: 'Writes outside the project on the engine host.',
  },
  {
    id: 'opt-in-litespeed-serial',
    match:
      /^(?:LiteSpeed Enterprise cannot start without|No LiteSpeed serial available — set) LITESPEED_SERIAL_NUMBER/,
    category: 'opt-in',
    why: 'Needs a LiteSpeed Enterprise licence.',
  },
  {
    id: 'opt-in-narrowed-mcp-catalogue',
    match: /^MCP_EXPECT_FULL_CATALOGUE=false /,
    category: 'opt-in',
    why: 'The operator declared a narrowed tool set.',
  },
  {
    id: 'opt-in-git-repo-override',
    match: /^GIT_REPO is overridden;/,
    category: 'opt-in',
    why: 'The assertion is written for the default repository.',
  },

  // Known issue: a defect or missing feature, tolerated until it is fixed.
  {
    id: 'private-ip-engine-ip-answered',
    match: /^The engine IP answered \d+, so its source address is not private/,
    category: 'known-issue',
    why: 'The engine IP is not a private source here; only the in-container test applies.',
    max: 1,
  },
  {
    id: 'mysql-privileges-not-implemented',
    match: /^GET mysql\/privileges is not implemented on this engine\./,
    category: 'known-issue',
    why: 'The endpoint is missing.',
    max: 2,
  },
];
