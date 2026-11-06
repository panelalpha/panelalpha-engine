import { checkServerIdentity } from 'node:tls';
import type { APIRequestContext } from '@playwright/test';
import { getTlsHandshake } from '@/helpers/tls-helpers';
import { fetchSite } from '@/helpers/webserver-helpers';

export interface FrontPage {
  /** Where the redirects ended. */
  url: string;
  status: number;
  title: string | null;
  bytes: number;
  body: string;
}

export interface ServedCertificate {
  names: string;
  issuer: string;
  validTo: string;
  /** The chain is trusted by the runner. */
  trusted: boolean;
  /** Null when the certificate is valid for the host, else why not. */
  hostProblem: string | null;
}

/** The page a browser lands on: redirects followed, the body read whole. */
export async function readFrontPage(request: APIRequestContext, url: string): Promise<FrontPage> {
  const response = await fetchSite(request, url, { timeout: 60_000, maxRedirects: 10 });
  const body = await response.text();
  const title = /<title[^>]*>([^<]*)<\/title>/i.exec(body)?.[1]?.trim() ?? null;
  return { url: response.url(), status: response.status(), title, bytes: body.length, body };
}

export function describeFrontPage(page: FrontPage): string {
  return `${page.status} ${page.url} <title>${page.title ?? ''}</title> ${page.bytes} bytes`;
}

/** The certificate the site's own host name gets, read with SNI the way a browser asks. */
export async function servedCertificate(host: string): Promise<ServedCertificate> {
  const { cert, authorized } = await getTlsHandshake(host, host);
  const problem = checkServerIdentity(host, cert);
  return {
    names: cert.subjectaltname ?? `CN=${String(cert.subject?.CN ?? '')}`,
    issuer: String(cert.issuer?.O ?? cert.issuer?.CN ?? ''),
    validTo: cert.valid_to,
    trusted: authorized,
    hostProblem: problem === undefined ? null : problem.message,
  };
}
