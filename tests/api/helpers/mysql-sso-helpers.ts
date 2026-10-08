import type { APIRequestContext } from '@playwright/test';
import type { ApiTransport } from '@/clients/api-transport';
import { registerSecret } from './secret-registry';

/** Query parameter the engine puts the single-use SSO credential in. */
export const SSO_QUERY_PARAM = 'pmassotoken';

/** Endpoint that trades an SSO credential for a phpMyAdmin session. */
export const SSO_EXCHANGE_PATH = 'mysql/phpmyadmin-sso-token';

export function phpMyAdminIndexUrl(domain: string): string {
  return `https://${domain}/phpmyadmin/index.php`;
}

/** The credential embedded in an SSO login URL, or `undefined` if absent. */
export function ssoCredentialFrom(loginUrl: string): string | undefined {
  const credential = new URL(loginUrl).searchParams.get(SSO_QUERY_PARAM) ?? undefined;
  registerSecret(credential);
  return credential;
}

/** A page is a login form when it still asks for a username or a password. */
export function isPhpMyAdminLoginForm(body: string): boolean {
  return /name=["']pma_username["']/i.test(body) || /name=["']pma_password["']/i.test(body);
}

/** A phpMyAdmin page that is past the login form — i.e. an established session. */
export function isPhpMyAdminLoggedIn(body: string): boolean {
  return body.toLowerCase().includes('phpmyadmin') && !isPhpMyAdminLoginForm(body);
}

export async function fetchPage(
  http: APIRequestContext,
  url: string
): Promise<{ status: number; body: string }> {
  const response = await http.get(url, { ignoreHTTPSErrors: true, maxRedirects: 5 });
  return { status: response.status(), body: await response.text() };
}

/** Trades an SSO credential for a session, returning the HTTP status only. */
export async function exchangeSsoCredential(
  transport: ApiTransport,
  credential: string
): Promise<number> {
  registerSecret(credential);
  const response = await transport.put(SSO_EXCHANGE_PATH, { data: { token: credential } });
  return response.status();
}
