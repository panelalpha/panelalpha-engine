import type { EngineApi } from '@/clients/engine-api';
import { redactAndTruncate } from './redact';
import { testNetwork } from './test-network';

/**
 * Response header the block mu-plugin adds to every request it sees, carrying the
 * REMOTE_ADDR it judged. It is how a test learns the address PHP really got,
 * instead of guessing it from the status.
 */
export const REMOTE_ADDR_HEADER = 'x-panelalpha-test-remote-addr';

/** Must-use plugin filename — removed after each test. */
export const LOCAL_IP_BLOCK_MU_PLUGIN = 'panelalpha-test-block-local-ips.php';

/** One-off probe script run via `wp eval-file` (removed after tests). */
export const WP_HTTP_PROBE_SCRIPT = 'panelalpha-wp-http-probe.php';

/** `cidr` as the stub's PHP pair of its first and last address. */
function phpRange(cidr: string): string {
  const [first, bits] = cidr.split('/');
  return `['${first}', '${testNetwork.add(first, 2 ** (32 - Number(bits)) - 1)}']`;
}

/**
 * Simulates WP firewall plugins that deny RFC1918 / loopback REMOTE_ADDR values.
 * Engine reverse-proxy and in-container HTTP often appear in these ranges.
 */
export const LOCAL_IP_BLOCK_MU_PLUGIN_PHP = `<?php
/**
 * PanelAlpha Engine API test stub — simulates WP security plugins blocking local/private IPs.
 * @see features/users/wp-cli/wp-cli-security-local-ip.feature
 */
if (!defined('ABSPATH')) {
    exit;
}

// WP-CLI reports REMOTE_ADDR ${testNetwork.LOOPBACK}; like the plugins this stands in for,
// only web requests are judged, or the probe run through wp eval-file is blocked.
if (defined('WP_CLI') && WP_CLI) {
    return;
}

add_action('plugins_loaded', static function (): void {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote !== '') {
        header('X-PanelAlpha-Test-Remote-Addr: ' . $remote);
    }
    if ($remote !== '' && panelalpha_test_is_private_or_local_ip($remote)) {
        status_header(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden: private/local IP blocked (PanelAlpha test mu-plugin)';
        exit;
    }
}, 0);

function panelalpha_test_is_private_or_local_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        $ranges = [
            ${phpRange(testNetwork.PRIVATE_10_CIDR)},
            ${phpRange(testNetwork.PRIVATE_172_CIDR)},
            ${phpRange(testNetwork.PRIVATE_192_CIDR)},
            ${phpRange(testNetwork.LOOPBACK_CIDR)},
        ];
        foreach ($ranges as [$start, $end]) {
            $startLong = ip2long($start);
            $endLong = ip2long($end);
            if ($startLong !== false && $endLong !== false && $long >= $startLong && $long <= $endLong) {
                return true;
            }
        }
        return false;
    }

    $lower = strtolower($ip);
    return $lower === '::1'
        || str_starts_with($lower, 'fc')
        || str_starts_with($lower, 'fd')
        || str_starts_with($lower, 'fe80:');
}
`;

export const WP_HTTP_PROBE_SCRIPT_PHP = `<?php
/**
 * Fetches one URL via wp_remote_get (in-container / WP-cron style) WITHOUT following
 * redirects and prints a single JSON line: status, Location and the REMOTE_ADDR the
 * block mu-plugin reported. The caller follows redirects, so each hop is visible.
 * Usage: wp eval-file panelalpha-wp-http-probe.php 'https://example.test/'
 */
$url = '';
if (isset($args[0]) && is_string($args[0])) {
    $url = $args[0];
} elseif (isset($GLOBALS['argv']) && is_array($GLOBALS['argv'])) {
    foreach (array_reverse($GLOBALS['argv']) as $candidate) {
        if (is_string($candidate) && preg_match('#^https?://#', $candidate)) {
            $url = $candidate;
            break;
        }
    }
}
if ($url === '') {
    fwrite(STDERR, 'no URL was passed to the probe');
    exit(3);
}
$response = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false, 'redirection' => 0]);
if (is_wp_error($response)) {
    fwrite(STDERR, $response->get_error_message());
    exit(2);
}
echo wp_json_encode([
    'probe' => 'panelalpha-wp-http-probe',
    'url' => $url,
    'status' => (int) wp_remote_retrieve_response_code($response),
    'location' => (string) wp_remote_retrieve_header($response, 'location'),
    'remote_addr' => (string) wp_remote_retrieve_header($response, '${REMOTE_ADDR_HEADER}'),
]);
`;

export function muPluginPath(wpPath: string): string {
  return `${wpPath}/wp-content/mu-plugins/${LOCAL_IP_BLOCK_MU_PLUGIN}`;
}

export function wpHttpProbePath(wpPath: string): string {
  return `${wpPath}/${WP_HTTP_PROBE_SCRIPT}`;
}

export async function installLocalIpBlockMuPlugin(
  api: EngineApi,
  username: string,
  wpPath: string
): Promise<void> {
  const muDir = `${wpPath}/wp-content/mu-plugins`;
  try {
    const exists = await api.fileExists(username, muDir);
    if (!exists.exists) {
      await api.createDirectory(username, muDir, true);
    }
  } catch {
    await api.createDirectory(username, muDir, true);
  }

  await api.putFileContents(username, muPluginPath(wpPath), LOCAL_IP_BLOCK_MU_PLUGIN_PHP);
}

export async function removeLocalIpBlockMuPlugin(
  api: EngineApi,
  username: string,
  wpPath: string
): Promise<void> {
  const path = muPluginPath(wpPath);
  const exists = await api.fileExists(username, path);
  if (exists.exists) {
    await api.removeFile(username, path, false);
  }
}

export async function installWpHttpProbeScript(
  api: EngineApi,
  username: string,
  wpPath: string
): Promise<void> {
  await api.putFileContents(username, wpHttpProbePath(wpPath), WP_HTTP_PROBE_SCRIPT_PHP);
}

export async function removeWpHttpProbeScript(
  api: EngineApi,
  username: string,
  wpPath: string
): Promise<void> {
  const path = wpHttpProbePath(wpPath);
  const exists = await api.fileExists(username, path);
  if (exists.exists) {
    await api.removeFile(username, path, false);
  }
}

/** One response of a redirect chain, as the probe saw it. */
export interface ProbeHop {
  url: string;
  status: number;
  /** The Location header, absolute; null when the response had none. */
  location: string | null;
  /** The REMOTE_ADDR the block mu-plugin judged; null when PHP never answered. */
  remoteAddr: string | null;
}

export interface ProbeChain {
  hops: ProbeHop[];
  final: ProbeHop;
  /** REMOTE_ADDR reported by the last hop that reached the mu-plugin. */
  remoteAddr: string | null;
}

/** What `wp eval-file` returned for one probe. */
export interface ProbeRun {
  exit_code: number;
  stdout: string;
  stderr: string;
}

/** The probe or its setup failed: not a verdict on the block. */
export class ProbeDidNotRunError extends Error {
  constructor(reason: string, run?: ProbeRun) {
    const output = run
      ? ` (exit ${run.exit_code}): stdout=${JSON.stringify(redactAndTruncate(run.stdout, 600))} ` +
        `stderr=${JSON.stringify(redactAndTruncate(run.stderr, 600))}`
      : '';
    super(`The in-container probe did not run${output || ''} - ${reason}`);
    this.name = 'ProbeDidNotRunError';
  }
}

/** Parses the probe's JSON line; null when stdout holds none. */
export function parseProbeOutput(stdout: string):
  | (Omit<ProbeHop, 'location'> & {
      location: string;
    })
  | null {
  const line = stdout
    .split('\n')
    .map((candidate) => candidate.trim())
    .reverse()
    .find((candidate) => candidate.startsWith('{') && candidate.endsWith('}'));
  if (!line) {
    return null;
  }
  try {
    const parsed = JSON.parse(line) as Record<string, unknown>;
    if (parsed.probe !== 'panelalpha-wp-http-probe' || !Number.isInteger(parsed.status)) {
      return null;
    }
    return {
      url: typeof parsed.url === 'string' ? parsed.url : '',
      status: Number(parsed.status),
      location: typeof parsed.location === 'string' ? parsed.location : '',
      remoteAddr:
        typeof parsed.remote_addr === 'string' && parsed.remote_addr ? parsed.remote_addr : null,
    };
  } catch {
    return null;
  }
}

/** The absolute URL a Location header points at, relative to the URL that sent it. */
export function resolveLocation(from: string, location: string): string {
  return new URL(location, from).toString();
}

export function isRedirectStatus(status: number): boolean {
  return [301, 302, 303, 307, 308].includes(status);
}

const MAX_PROBE_HOPS = 5;

/**
 * Probes `startUrl` and follows its redirects one hop at a time, so the chain that
 * reaches PHP (for example http -> https from force-HTTPS) is part of the result.
 * Throws ProbeDidNotRunError when a probe exits non-zero or prints no parsable status.
 */
export async function followProbe(
  run: (url: string) => Promise<ProbeRun>,
  startUrl: string,
  maxHops = MAX_PROBE_HOPS
): Promise<ProbeChain> {
  const hops: ProbeHop[] = [];
  let url = startUrl;

  for (let attempt = 0; attempt <= maxHops; attempt += 1) {
    const result = await run(url);
    if (result.exit_code !== 0) {
      throw new ProbeDidNotRunError('wp eval-file exited non-zero', result);
    }
    const parsed = parseProbeOutput(result.stdout);
    if (!parsed) {
      throw new ProbeDidNotRunError('no parsable status in its output', result);
    }

    const location = parsed.location ? resolveLocation(url, parsed.location) : null;
    const hop: ProbeHop = { url, status: parsed.status, location, remoteAddr: parsed.remoteAddr };
    hops.push(hop);

    if (!isRedirectStatus(hop.status) || !location) {
      return finishChain(hops);
    }
    url = location;
  }

  throw new ProbeDidNotRunError(
    `the redirect chain did not settle in ${maxHops} hops: ${describeChain(hops)}`
  );
}

function finishChain(hops: ProbeHop[]): ProbeChain {
  const withAddr = [...hops].reverse().find((hop) => hop.remoteAddr);
  return { hops, final: hops[hops.length - 1], remoteAddr: withAddr?.remoteAddr ?? null };
}

/** `url -> status [Location] (REMOTE_ADDR x)` per hop, for failure messages. */
export function describeChain(hops: readonly ProbeHop[]): string {
  return hops
    .map(
      (hop) =>
        `${hop.url} -> ${hop.status}` +
        (hop.location ? ` [Location ${hop.location}]` : '') +
        (hop.remoteAddr ? ` (REMOTE_ADDR ${hop.remoteAddr})` : ' (no mu-plugin header)')
    )
    .join(' ; ');
}

/**
 * Fetches a URL from inside the WordPress install (wp_remote_get), as Engine/cron would,
 * following redirects hop by hop. A probe that does not run throws ProbeDidNotRunError.
 */
export async function wpRemoteGetChain(
  api: EngineApi,
  username: string,
  wpPath: string,
  targetUrl: string
): Promise<ProbeChain> {
  const probePath = wpHttpProbePath(wpPath);
  return followProbe(
    (url) => api.executeWpCliCommand(username, ['eval-file', probePath, `--path=${wpPath}`, url]),
    targetUrl
  );
}

/** One request to the engine address, as the caller's HTTP client reports it. */
export interface EngineIpResponse {
  status: number;
  location: string | null;
  remoteAddr: string | null;
  body: string;
}

export interface EngineIpChain {
  hops: ProbeHop[];
  final: EngineIpResponse;
  remoteAddr: string | null;
}

/**
 * Requests `scheme://engineHost/` with the site's Host header and follows redirects
 * to the same engine address, taking scheme, Host and path from each Location (so a
 * force-HTTPS 301 continues over https, and a canonical redirect to `www.` asks for
 * the www host, both still at the engine address rather than at a public name).
 */
export async function followViaEngineIp(
  fetchOnce: (url: string, hostHeader: string) => Promise<EngineIpResponse>,
  scheme: string,
  engineHost: string,
  siteDomain: string,
  maxHops = MAX_PROBE_HOPS
): Promise<EngineIpChain> {
  const hops: ProbeHop[] = [];
  let url = `${scheme}://${engineHost}/`;
  let sentAs = `${scheme}://${siteDomain}/`;

  for (let attempt = 0; attempt <= maxHops; attempt += 1) {
    const response = await fetchOnce(url, new URL(sentAs).host);
    const location = response.location ? resolveLocation(sentAs, response.location) : null;
    hops.push({ url, status: response.status, location, remoteAddr: response.remoteAddr });

    if (!isRedirectStatus(response.status) || !location) {
      const chain = finishChain(hops);
      return { hops, final: response, remoteAddr: chain.remoteAddr };
    }

    const next = new URL(location);
    sentAs = location;
    url = `${next.protocol}//${engineHost}${next.pathname}${next.search}`;
  }

  throw new Error(
    `The engine address redirect chain did not settle in ${maxHops} hops: ${describeChain(hops)}`
  );
}

export { resolveEngineIpv4 } from './engine-host';
