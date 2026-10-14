import { X509Certificate } from 'node:crypto';
import * as tls from 'node:tls';

import { isIpLiteral } from '@/config/site-domain';
import { expect, test } from '@/fixtures/test-options';
import { requireEngineConnectHost } from '@/helpers/engine-host';
import { isPrivateOrLocalIp } from '@/helpers/real-ip-helpers';
import { skipUnless } from '@/helpers/test-helpers';
import {
  getTlsHandshake,
  installedTestSslCertificatePresented,
  isLetsEncryptIssued,
} from '@/helpers/tls-helpers';
import type { SystemInfo } from '@/types';

const ONE_DAY_MS = 24 * 60 * 60 * 1000;

function apiTlsPort(): number {
  const base = process.env.API_BASE_URL?.trim() ?? 'https://localhost:2011/api/';
  const fromUrl = new URL(base).port;
  return fromUrl ? Number.parseInt(fromUrl, 10) : 2011;
}

/**
 * An engine reached only on a private address cannot get a publicly trusted
 * certificate, so it serves the self-signed one by design. A self-signed
 * certificate on a public address is still a failure.
 */
function skipWhenPrivateAndSelfSignedByDesign(info: SystemInfo, hostname: string): void {
  const status = info.served_certificate?.status;
  test.skip(
    isIpLiteral(hostname) && isPrivateOrLocalIp(hostname) && status === 'self_signed',
    `The engine is addressed at the private IP ${hostname} and serves its self-signed certificate by design; no public authority issues one for a private address.`
  );
}

function sniHostnameFromApiUrl(apiUrl: string | null | undefined): string | undefined {
  const trimmed = apiUrl?.trim();
  if (!trimmed) {
    return undefined;
  }
  try {
    return new URL(trimmed).hostname;
  } catch {
    return undefined;
  }
}

test.describe('engine TLS', () => {
  test(
    "API port presents a valid Let's Encrypt certificate",
    { tag: ['@smoke'] },
    async ({ api }) => {
      // Prefer API_BASE_URL when it already targets the engine by IP (e.g. tunnel
      // setups); otherwise resolve default_ipv4 from system/info.
      const apiBaseUrl = process.env.API_BASE_URL?.trim() ?? '';
      const apiHost = apiBaseUrl ? new URL(apiBaseUrl).hostname : '';
      const host = isIpLiteral(apiHost) ? apiHost : await requireEngineConnectHost(api);
      const port = apiTlsPort();
      const info = (await api.getSystemInfo()).data;
      const hostname = sniHostnameFromApiUrl(info.api_url);
      skipUnless(hostname, 'system/info api_url is missing; cannot determine TLS identity host.');
      skipWhenPrivateAndSelfSignedByDesign(info, hostname);
      // LE issues IP SANs; Node forbids IP literals in TLS servername, so omit SNI for IPs.
      const servername = isIpLiteral(hostname) ? '' : hostname;

      const { cert, authorized, authorizationError } = await getTlsHandshake(
        host,
        servername,
        port
      );

      expect(
        authorized,
        authorizationError ? `TLS not authorized: ${authorizationError}` : 'TLS not authorized'
      ).toBe(true);
      expect(isLetsEncryptIssued(cert)).toBe(true);
      const x509 = new X509Certificate(cert.raw);
      expect(x509.issuer).not.toBe(x509.subject);
      expect(installedTestSslCertificatePresented(cert)).toBe(false);

      const now = Date.now();
      const validFrom = new Date(cert.valid_from).getTime();
      const validTo = new Date(cert.valid_to).getTime();
      expect(validFrom).toBeLessThanOrEqual(now);
      expect(now).toBeLessThan(validTo);
      expect(validTo - now).toBeGreaterThan(ONE_DAY_MS);

      expect(tls.checkServerIdentity(hostname, cert)).toBeUndefined();
    }
  );
});
