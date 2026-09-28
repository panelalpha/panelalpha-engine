import * as fs from 'fs';
import * as path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * The engine repository root.
 *
 * The suite lives at `<engine>/tests/api`, so the engine's own templates and
 * config assets are two directories up — no bundle checkout needed, unlike when
 * this suite was a separate repository.
 */
export const engineRoot = path.resolve(__dirname, '../../..');

/** Engine vhost and config templates (`<engine>/templates`). */
export const engineTemplatesRoot = path.join(engineRoot, 'templates');

/** RFC5737 documentation IPv4 used as a synthetic Cloudflare client IP in probes. */
export const CLOUDFLARE_PROBE_CLIENT_IP = '203.0.113.10';

export const REAL_IP_PROBE_RELATIVE_PATH = 'public_html/real-ip-probe.php';

export const REAL_IP_PROBE_PHP = `<?php
header('Content-Type: application/json');
echo json_encode([
    'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? null,
    'cf_ip' => $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
    'xff' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
    'x_real_ip' => $_SERVER['HTTP_X_REAL_IP'] ?? null,
]);
`;

export interface RealIpProbePayload {
  remote_addr: string | null;
  cf_ip: string | null;
  xff: string | null;
  x_real_ip: string | null;
}

export interface RealIpEngineAssetCheck {
  /** Path relative to \`engine/\`. */
  relativePath: string;
  mustContain: string[];
  /** Slug substring match; when set, check runs only for matching webservers. */
  webserverIncludes?: string;
}

export function webserverMatchesAssetCheck(slug: string, webserverIncludes?: string): boolean {
  if (!webserverIncludes) {
    return true;
  }
  const normalized = slug.toLowerCase();
  if (webserverIncludes === 'litespeed') {
    return normalized.includes('litespeed') && !normalized.includes('openlitespeed');
  }
  return normalized.includes(webserverIncludes);
}

export const REAL_IP_ENGINE_ASSET_CHECKS: RealIpEngineAssetCheck[] = [
  {
    relativePath: 'templates/user/default/project/apache-conf/remoteip.conf',
    mustContain: ['RemoteIPHeader X-Real-IP', '172.16.0.0/12'],
    webserverIncludes: 'nginx-proxy',
  },
  {
    relativePath: 'templates/user/default/project/docker-compose.yml-fpm-apache.blade.php',
    mustContain: ['apache-conf/remoteip.conf:/etc/apache2/conf-enabled/remoteip.conf'],
    webserverIncludes: 'nginx-proxy',
  },
  {
    relativePath: 'templates/virtualHost-nginx-proxy.blade.php',
    mustContain: ['proxy_set_header X-Real-IP $remote_addr'],
    webserverIncludes: 'nginx-proxy',
  },
  {
    relativePath: 'templates/webserver-nginx-proxy.blade.php',
    mustContain: ['cloudflare-realip'],
    webserverIncludes: 'nginx-proxy',
  },
  {
    relativePath: 'templates/webserver-nginx.blade.php',
    mustContain: ['cloudflare-realip'],
    webserverIncludes: 'nginx',
  },
  {
    relativePath: 'templates/webserver-apache.blade.php',
    mustContain: ['cloudflare-realip', 'remoteip_module'],
    webserverIncludes: 'apache',
  },
  {
    relativePath: 'scripts/update-cloudflare-ips.sh',
    mustContain: ['webserver-config/openlitespeed/cloudflare-realip.conf', 'useIpInProxy 2'],
    webserverIncludes: 'openlitespeed',
  },
  {
    relativePath: 'scripts/update-cloudflare-ips.sh',
    mustContain: ['webserver-config/litespeed/cloudflare-realip.xml'],
    webserverIncludes: 'litespeed',
  },
  {
    relativePath: 'core/app/Lib/Apis/System/Services/Webserver/LiteSpeedTrait.php',
    mustContain: ['applyCloudflareRealIpConfig'],
    webserverIncludes: 'litespeed',
  },
  {
    relativePath: 'core/app/Lib/Apis/System/Services/Webserver/LiteSpeedTrait.php',
    mustContain: ['applyCloudflareRealIpConfig'],
    webserverIncludes: 'openlitespeed',
  },
];

export function resolveEngineAssetPath(relativePath: string): string {
  return path.join(engineRoot, relativePath);
}

export function engineAssetsAvailable(): boolean {
  return fs.existsSync(engineRoot);
}

/** Returns true for RFC1918, loopback, and IPv6 local/link-local ranges. */
export function isPrivateOrLocalIp(ip: string | null | undefined): boolean {
  if (!ip) {
    return false;
  }

  const trimmed = ip.trim();
  if (!trimmed) {
    return false;
  }

  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(trimmed)) {
    const parts = trimmed.split('.').map((part) => Number.parseInt(part, 10));
    if (parts.some((part) => part < 0 || part > 255)) {
      return false;
    }
    const [a, b] = parts;
    if (a === 10) {
      return true;
    }
    if (a === 127) {
      return true;
    }
    if (a === 192 && b === 168) {
      return true;
    }
    if (a === 172 && b >= 16 && b <= 31) {
      return true;
    }
    return false;
  }

  const lower = trimmed.toLowerCase();
  return (
    lower === '::1' || lower.startsWith('fc') || lower.startsWith('fd') || lower.startsWith('fe80:')
  );
}

export function realIpProbeUrl(domain: string): string {
  return `https://${domain}/real-ip-probe.php`;
}

export function realIpProbeFilePath(domain: string): string {
  return `/${domain}/${REAL_IP_PROBE_RELATIVE_PATH}`;
}
