import { expect, test } from '@/fixtures/test-options';
import type { APIRequestContext } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import {
  probeModsecurityHostingCompatible,
  resetModsecurityHostingProbeCache,
  type ModsecProbeDeps,
} from '@/helpers/modsec-hosting-probe';

/** Pure unit coverage of the probe cache: fakes stand in for the Engine and the site. */

interface Fakes {
  api: EngineApi;
  http: APIRequestContext;
  deps: ModsecProbeDeps;
  modeWrites: string[];
  gets: number;
}

/** `brokenAfterRestore`: the site answers 503 once the mode is no longer "on". */
function fakes(options: { brokenAfterRestore?: boolean; slug?: string } = {}): Fakes {
  const state = { modeWrites: [] as string[], gets: 0, mode: 'detection_only' };
  const api = {
    getModSecurityConfig: () => Promise.resolve({ data: { mode: state.mode } }),
    setModSecurityConfig: (body: { mode: string }) => {
      state.modeWrites.push(body.mode);
      state.mode = body.mode;
      return Promise.resolve({ data: {} });
    },
  } as unknown as EngineApi;
  const http = {
    get: () => {
      state.gets += 1;
      return Promise.resolve({
        status: () => (options.brokenAfterRestore && state.mode !== 'on' ? 503 : 200),
      });
    },
  } as unknown as APIRequestContext;
  const slug = options.slug ?? 'nginx';
  return {
    api,
    http,
    deps: { sleep: () => Promise.resolve(), webserverSlug: () => Promise.resolve(slug) },
    get modeWrites() {
      return state.modeWrites;
    },
    get gets() {
      return state.gets;
    },
  };
}

test.describe('ModSecurity hosting probe cache', () => {
  test.beforeEach(() => resetModsecurityHostingProbeCache());

  test('a compatible verdict is probed once and then reused', async () => {
    const f = fakes();
    const first = await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    const writesAfterFirst = f.modeWrites.length;
    const second = await probeModsecurityHostingCompatible(
      f.api,
      f.http,
      'https://a.test/',
      f.deps
    );

    expect(first.compatible).toBe(true);
    expect(second).toBe(first);
    expect(writesAfterFirst).toBeGreaterThan(0);
    expect(f.modeWrites).toHaveLength(writesAfterFirst);
  });

  test('an incompatible verdict is reused too', async () => {
    const f = fakes({ brokenAfterRestore: true });
    const first = await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    const writes = f.modeWrites.length;
    const second = await probeModsecurityHostingCompatible(
      f.api,
      f.http,
      'https://a.test/',
      f.deps
    );

    expect(first.compatible).toBe(false);
    expect(second).toBe(first);
    expect(f.modeWrites).toHaveLength(writes);
  });

  test('another site host or webserver is probed again', async () => {
    const f = fakes();
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    const afterFirst = f.modeWrites.length;

    await probeModsecurityHostingCompatible(f.api, f.http, 'https://b.test/', f.deps);
    const afterOtherHost = f.modeWrites.length;
    expect(afterOtherHost).toBeGreaterThan(afterFirst);

    const apache = fakes({ slug: 'apache' });
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', apache.deps);
    expect(f.modeWrites.length).toBeGreaterThan(afterOtherHost);
  });

  test('the path of the probe URL does not change the key', async () => {
    const f = fakes();
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    const writes = f.modeWrites.length;
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/other', f.deps);
    expect(f.modeWrites).toHaveLength(writes);
  });

  test('an unreadable webserver is never cached', async () => {
    const f = fakes({ slug: '' });
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    const writes = f.modeWrites.length;
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    expect(f.modeWrites.length).toBeGreaterThan(writes);
  });

  test('the reset forgets every verdict', async () => {
    const f = fakes();
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    const writes = f.modeWrites.length;
    resetModsecurityHostingProbeCache();
    await probeModsecurityHostingCompatible(f.api, f.http, 'https://a.test/', f.deps);
    expect(f.modeWrites.length).toBeGreaterThan(writes);
  });
});
