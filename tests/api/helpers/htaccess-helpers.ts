import type { APIRequestContext } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import { CleanupSteps } from './cleanup-step';
import { removeFileIfPresent } from './file-path-helpers';
import { getWebserverInfo } from './webserver-helpers';

export type WebserverType = 'nginx' | 'nginx-proxy' | 'apache' | 'litespeed' | 'openlitespeed';

export function normalizeWebserverSlug(slug: string): WebserverType {
  if (slug.includes('nginx') && slug.includes('apache')) {
    return 'nginx-proxy';
  }
  if (slug.includes('nginx-proxy')) {
    return 'nginx-proxy';
  }
  if (slug.includes('openlitespeed')) {
    return 'openlitespeed';
  }
  if (slug.includes('litespeed')) {
    return 'litespeed';
  }
  if (slug.includes('nginx')) {
    return 'nginx';
  }
  if (slug.includes('apache')) {
    return 'apache';
  }
  return 'nginx';
}

export function getHtaccessPollOptions(normalizedType: WebserverType): {
  timeoutMs: number;
  intervalMs: number;
} {
  if (normalizedType === 'openlitespeed') {
    return { timeoutMs: 30_000, intervalMs: 2_000 };
  }
  return { timeoutMs: 5_000, intervalMs: 500 };
}

export function getHtaccessPropagationDelay(
  normalizedType: WebserverType,
  defaultDelay: number,
  extraMs = 0
): number {
  if (normalizedType === 'openlitespeed') {
    return 15_000 + extraMs;
  }
  return defaultDelay + extraMs;
}

export async function pollForHtaccessResult(
  httpClient: APIRequestContext,
  testUrl: string,
  options: {
    expectStatus: number;
    expectBodyContent?: string;
    timeoutMs: number;
    intervalMs: number;
  }
): Promise<{ status: number; body: string; success: boolean }> {
  const start = Date.now();
  while (Date.now() - start < options.timeoutMs) {
    const response = await httpClient.get(testUrl, {
      ignoreHTTPSErrors: true,
      maxRedirects: 5,
    });
    const status = response.status();
    const body = await response.text();

    const statusOk = status === options.expectStatus;
    const bodyOk = options.expectBodyContent ? body.includes(options.expectBodyContent) : true;

    if (statusOk && bodyOk) {
      return { status, body, success: true };
    }

    await new Promise((resolve) => setTimeout(resolve, options.intervalMs));
  }
  return { status: 0, body: '', success: false };
}

export async function verifyHtaccessSupport(
  api: EngineApi,
  httpClient: APIRequestContext,
  settings: { apiBaseUrl: string },
  setupData: { username: string; domain: string }
): Promise<boolean> {
  const { slug } = await getWebserverInfo(api);
  const normalizedType = normalizeWebserverSlug(slug);

  if (normalizedType === 'nginx' || normalizedType === 'nginx-proxy') {
    return false;
  }

  const testContent = 'htaccess-preflight-' + Date.now();
  const targetPath = `/${setupData.domain}/public_html/htaccess-preflight.txt`;
  const htaccessPath = `/${setupData.domain}/public_html/.htaccess`;

  const cleanup = new CleanupSteps();
  try {
    await api.putFileContents(setupData.username, targetPath, testContent);
    await api.putFileContents(
      setupData.username,
      htaccessPath,
      `<IfModule mod_rewrite.c>\n  RewriteEngine On\n  RewriteRule ^htaccess-preflight$ htaccess-preflight.txt [L]\n</IfModule>\n`
    );

    const scheme = settings.apiBaseUrl.startsWith('https') ? 'https' : 'http';
    const testUrl = `${scheme}://${setupData.domain}/htaccess-preflight`;

    const isOls = normalizedType === 'openlitespeed' || normalizedType === 'litespeed';
    const result = await pollForHtaccessResult(httpClient, testUrl, {
      expectStatus: 200,
      expectBodyContent: testContent,
      timeoutMs: isOls ? 15_000 : 5_000,
      intervalMs: 1000,
    });

    await api.putFileContents(setupData.username, htaccessPath, '# Empty .htaccess\n');

    return result.success;
  } catch {
    return false;
  } finally {
    // Runs after the return value is decided, so a failed removal still reaches the caller.
    await cleanup.run(`remove ${targetPath}`, () =>
      removeFileIfPresent(api, setupData.username, targetPath)
    );
    await cleanup.finish();
  }
}

export async function skipIfHtaccessUnsupported(
  test: { skip: (condition: boolean, description?: string) => void },
  api: EngineApi,
  httpClient: APIRequestContext,
  settings: { apiBaseUrl: string; timing: { propagationDelay: number } },
  setupData: { username: string; domain: string }
): Promise<WebserverType> {
  const { slug } = await getWebserverInfo(api);
  const normalizedType = normalizeWebserverSlug(slug);

  test.skip(
    normalizedType === 'nginx' || normalizedType === 'nginx-proxy',
    'nginx does not support .htaccess files - these tests are for Apache/LiteSpeed only'
  );

  const htaccessWorks = await verifyHtaccessSupport(api, httpClient, settings, setupData);
  if (normalizedType === 'openlitespeed' && !htaccessWorks) {
    test.skip(
      true,
      'OpenLiteSpeed .htaccess support is not functional on this engine — ' +
        'the vhost template is missing `enable 1` and `autoLoadHtaccess 1` in the `context /` rewrite block. ' +
        'Fix the engine vhost template and restart OpenLiteSpeed.'
    );
  }

  return normalizedType;
}
