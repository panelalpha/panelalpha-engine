import { createServer, type Server } from 'node:http';
import type { AddressInfo } from 'node:net';
import { expect, test } from '@/fixtures/test-options';
import { fetchSite } from '@/helpers/webserver-helpers';

// Answers like a Rails HTML route: 406 unless the client accepts HTML.
function startHtmlOnlyApp(): Promise<{ server: Server; url: string; seen: string[] }> {
  const seen: string[] = [];
  const server = createServer((request, response) => {
    const accept = request.headers.accept ?? '';
    seen.push(accept);
    const html = accept === '' || /text\/html|\*\/\*/.test(accept);
    response.writeHead(html ? 200 : 406, { 'Content-Type': 'text/html', Vary: 'Accept' });
    response.end(html ? '<title>Login</title>' : '');
  });
  return new Promise((resolve) => {
    server.listen(0, '127.0.0.1', () => {
      const { port } = server.address() as AddressInfo;
      resolve({ server, url: `http://127.0.0.1:${port}/`, seen });
    });
  });
}

test.describe('fetchSite', () => {
  test('asks for a page, not JSON, through the anonymous API client', async ({ playwright }) => {
    const app = await startHtmlOnlyApp();
    // The same headers the anonymousRequest fixture sets for API calls.
    const client = await playwright.request.newContext({
      extraHTTPHeaders: { Accept: 'application/json' },
    });
    try {
      const site = await fetchSite(client, app.url);
      expect(site.status()).toBe(200);
      expect(app.seen[0]).toContain('text/html');
    } finally {
      await client.dispose();
      app.server.close();
    }
  });

  test('a caller can still ask for something else', async ({ playwright }) => {
    const app = await startHtmlOnlyApp();
    const client = await playwright.request.newContext();
    try {
      const site = await fetchSite(client, app.url, { headers: { Accept: 'application/json' } });
      expect(site.status()).toBe(406);
    } finally {
      await client.dispose();
      app.server.close();
    }
  });
});
