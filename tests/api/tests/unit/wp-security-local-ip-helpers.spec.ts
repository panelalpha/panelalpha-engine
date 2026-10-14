import { expect, test } from '@/fixtures/test-options';
import {
  LOCAL_IP_BLOCK_MU_PLUGIN_PHP,
  ProbeDidNotRunError,
  REMOTE_ADDR_HEADER,
  WP_HTTP_PROBE_SCRIPT_PHP,
  followProbe,
  followViaEngineIp,
  parseProbeOutput,
  type EngineIpResponse,
  type ProbeRun,
} from '@/helpers/wp-security-local-ip-helpers';

/**
 * The private-IP probe: what counts as a probe that ran, how redirects are followed,
 * and which address the block mu-plugin reports.
 */

function line(fields: Record<string, unknown>): string {
  return JSON.stringify({ probe: 'panelalpha-wp-http-probe', url: 'u', location: '', ...fields });
}

function run(stdout: string, exit_code = 0, stderr = ''): ProbeRun {
  return { exit_code, stdout, stderr };
}

test.describe('parseProbeOutput', () => {
  test('reads the JSON line even behind PHP notices', () => {
    const parsed = parseProbeOutput(
      `Notice: something\n${line({ status: 403, remote_addr: '172.18.0.2' })}\n`
    );
    expect(parsed).toMatchObject({ status: 403, remoteAddr: '172.18.0.2', location: '' });
  });

  test('returns null for an empty, bare-number or foreign output', () => {
    expect(parseProbeOutput('')).toBeNull();
    expect(parseProbeOutput('403')).toBeNull();
    expect(parseProbeOutput('{"status":403}')).toBeNull();
    expect(parseProbeOutput('{broken')).toBeNull();
  });
});

test.describe('followProbe', () => {
  test('follows a 301 to https and keeps the REMOTE_ADDR of the hop that reached PHP', async () => {
    const seen: string[] = [];
    const chain = await followProbe((url) => {
      seen.push(url);
      return Promise.resolve(
        url.startsWith('http://')
          ? run(line({ status: 301, location: 'https://site.test/' }))
          : run(line({ status: 403, remote_addr: '10.0.0.5' }))
      );
    }, 'http://site.test/');

    expect(seen).toEqual(['http://site.test/', 'https://site.test/']);
    expect(chain.final.status).toBe(403);
    expect(chain.remoteAddr).toBe('10.0.0.5');
    expect(chain.hops[0].location).toBe('https://site.test/');
  });

  test('resolves a relative Location against the URL that sent it', async () => {
    const seen: string[] = [];
    await followProbe((url) => {
      seen.push(url);
      return Promise.resolve(
        seen.length === 1
          ? run(line({ status: 302, location: '/home/' }))
          : run(line({ status: 200 }))
      );
    }, 'https://site.test/');

    expect(seen[1]).toBe('https://site.test/home/');
  });

  test('exit 0 without a parsable status is a probe failure that keeps stdout and stderr', async () => {
    const error = await followProbe(
      () => Promise.resolve(run('Deprecated: x\n', 0, 'warn: y')),
      'https://site.test/'
    ).catch((caught: unknown) => caught);

    expect(error).toBeInstanceOf(ProbeDidNotRunError);
    expect((error as Error).message).toContain('did not run (exit 0)');
    expect((error as Error).message).toContain('Deprecated: x');
    expect((error as Error).message).toContain('warn: y');
  });

  test('a non-zero exit is a probe failure', async () => {
    await expect(
      followProbe(() => Promise.resolve(run('', 2, 'cURL error 6')), 'https://site.test/')
    ).rejects.toThrow(/did not run \(exit 2\).*cURL error 6/);
  });

  test('a redirect loop is reported, not followed forever', async () => {
    await expect(
      followProbe(
        () => Promise.resolve(run(line({ status: 301, location: '/' }))),
        'https://site.test/',
        2
      )
    ).rejects.toThrow(/did not settle in 2 hops/);
  });
});

test.describe('followViaEngineIp', () => {
  const reply = (fields: Partial<EngineIpResponse>): EngineIpResponse => ({
    status: 200,
    location: null,
    remoteAddr: null,
    body: '',
    ...fields,
  });

  test('continues a force-HTTPS 301 against the engine address, not the public name', async () => {
    const seen: string[] = [];
    const chain = await followViaEngineIp(
      (url) => {
        seen.push(url);
        return Promise.resolve(
          url.startsWith('http://')
            ? reply({ status: 301, location: 'https://site.test/shop?x=1' })
            : reply({ status: 403, remoteAddr: '172.17.0.1', body: 'Forbidden' })
        );
      },
      'http',
      '203.0.113.7',
      'site.test'
    );

    expect(seen).toEqual(['http://203.0.113.7/', 'https://203.0.113.7/shop?x=1']);
    expect(chain.final.status).toBe(403);
    expect(chain.remoteAddr).toBe('172.17.0.1');
  });

  test('a canonical redirect to www asks the engine address for the www host', async () => {
    const seen: string[] = [];
    const chain = await followViaEngineIp(
      (url, hostHeader) => {
        seen.push(`${hostHeader} ${url}`);
        return Promise.resolve(
          hostHeader === 'site.test'
            ? reply({ status: 301, location: 'https://www.site.test/', remoteAddr: '198.51.100.9' })
            : reply({ status: 403, remoteAddr: '172.17.0.1', body: 'Forbidden' })
        );
      },
      'https',
      '203.0.113.7',
      'site.test'
    );

    expect(seen).toEqual(['site.test https://203.0.113.7/', 'www.site.test https://203.0.113.7/']);
    expect(chain.final.status).toBe(403);
    expect(chain.remoteAddr).toBe('172.17.0.1');
  });

  test('reports no REMOTE_ADDR when PHP never answered', async () => {
    const chain = await followViaEngineIp(
      () => Promise.resolve(reply({ status: 404 })),
      'https',
      '203.0.113.7',
      'site.test'
    );
    expect(chain.remoteAddr).toBeNull();
  });
});

test.describe('the helper scripts', () => {
  test('the mu-plugin reports the address it judged under the header the tests read', () => {
    expect(LOCAL_IP_BLOCK_MU_PLUGIN_PHP.toLowerCase()).toContain(REMOTE_ADDR_HEADER);
  });

  test('the probe does not follow redirects itself and reads the same header', () => {
    expect(WP_HTTP_PROBE_SCRIPT_PHP).toContain("'redirection' => 0");
    expect(WP_HTTP_PROBE_SCRIPT_PHP).toContain(REMOTE_ADDR_HEADER);
  });
});
