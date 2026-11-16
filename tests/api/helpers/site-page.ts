import type { APIRequestContext } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import { expect } from '@playwright/test';
import { getDomainBasePath, randomFileContent } from '@/helpers/file-path-helpers';
import { fetchSite } from '@/helpers/webserver-helpers';

export interface SitePage {
  url: string;
  marker: string;
}

export interface PageAnswer {
  status: number;
  body: string;
  headers: Record<string, string>;
}

/** Writes an HTML index page holding a unique marker into the domain's document root. */
export async function writeMarkerPage(
  api: EngineApi,
  username: string,
  domain: string
): Promise<SitePage> {
  const marker = randomFileContent();
  const html = `<!doctype html><html><head><title>${marker}</title></head><body><p>${marker}</p></body></html>`;
  await api.putFileContents(username, `${getDomainBasePath(domain)}/index.html`, html);
  return { url: `https://${domain}/`, marker };
}

export async function readPage(
  request: APIRequestContext,
  url: string,
  options: Parameters<APIRequestContext['get']>[1] = {}
): Promise<PageAnswer> {
  const response = await fetchSite(request, url, { maxRedirects: 0, ...options });
  return { status: response.status(), body: await response.text(), headers: response.headers() };
}

/** Polls the page until `accept` says yes, carrying the last answer into the timeout message. */
export async function waitForPage(
  request: APIRequestContext,
  url: string,
  accept: (page: PageAnswer) => boolean,
  message: string,
  options: Parameters<APIRequestContext['get']>[1] = {}
): Promise<PageAnswer> {
  let last: PageAnswer = { status: 0, body: '', headers: {} };
  await expect
    .poll(
      async () => {
        try {
          last = await readPage(request, url, options);
        } catch (error) {
          return error instanceof Error ? error.message : String(error);
        }
        return accept(last) ? 'yes' : `HTTP ${last.status}: ${last.body.slice(0, 120)}`;
      },
      { timeout: 90_000, intervals: [1_000, 2_000], message }
    )
    .toBe('yes');
  return last;
}

export function servesMarker(page: SitePage): (answer: PageAnswer) => boolean {
  return (answer) => answer.status === 200 && answer.body.includes(page.marker);
}
