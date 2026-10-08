import { expect, test } from '@/fixtures/test-options';
import type { CleanupTestInfo } from '@/helpers/cleanup-step';
import { SharedProject, type SharedProjectSource } from '@/helpers/shared-project';

function fakeInfo(): CleanupTestInfo {
  return {
    annotations: [] as { type: string; description?: string }[],
    attach: () => Promise.resolve(),
  };
}

interface Fake {
  source: SharedProjectSource;
  opens: number;
  provisions: number;
  closed: boolean;
  removed: string[];
}

function fake(
  options: {
    provision?: () => Promise<{ username: string; domain: string } | null>;
    pending?: string[];
    failRemove?: boolean;
  } = {}
): Fake {
  let pending = options.pending ?? [];
  const state: Fake = {
    opens: 0,
    provisions: 0,
    closed: false,
    removed: [],
    source: {
      provision: () => {
        state.provisions += 1;
        return options.provision
          ? options.provision()
          : Promise.resolve({ username: 'proj1', domain: 'proj1.test' });
      },
      pending: () => pending,
      remove: (username) => {
        if (options.failRemove) {
          return Promise.reject(new Error(`cannot delete ${username}`));
        }
        state.removed.push(username);
        pending = pending.filter((name) => name !== username);
        return Promise.resolve();
      },
      close: () => {
        state.closed = true;
        return Promise.resolve();
      },
    },
  };
  return state;
}

function sharedOver(f: Fake): SharedProject {
  return new SharedProject(() => {
    f.opens += 1;
    return Promise.resolve(f.source);
  });
}

test.describe('SharedProject', () => {
  test('provisions once for any number of callers', async () => {
    const f = fake({ pending: ['proj1'] });
    const shared = sharedOver(f);
    const [a, b] = await Promise.all([shared.get(), shared.get()]);
    const c = await shared.get();
    expect(a).toEqual({ username: 'proj1', domain: 'proj1.test' });
    expect(b).toBe(a);
    expect(c).toBe(a);
    expect(f.opens).toBe(1);
    expect(f.provisions).toBe(1);
  });

  test('an explicit refusal is returned as null to every caller and nothing is deleted', async () => {
    const f = fake({ provision: () => Promise.resolve(null) });
    const shared = sharedOver(f);
    expect(await shared.get()).toBeNull();
    expect(await shared.get()).toBeNull();
    await shared.dispose(fakeInfo());
    expect(f.provisions).toBe(1);
    expect(f.removed).toEqual([]);
    expect(f.closed).toBe(true);
  });

  test('a failed provisioning fails every caller with the original error and is not retried', async () => {
    const original = new Error('POST /projects answered 500');
    const f = fake({ provision: () => Promise.reject(original), pending: ['half-made'] });
    const shared = sharedOver(f);
    await expect(shared.get()).rejects.toBe(original);
    await expect(shared.get()).rejects.toBe(original);
    expect(f.provisions).toBe(1);

    await shared.dispose(fakeInfo());
    expect(f.removed).toEqual(['half-made']);
    expect(f.closed).toBe(true);
  });

  test('a source that cannot be opened fails every caller with that error', async () => {
    const original = new Error('no context');
    const shared = new SharedProject(() => Promise.reject(original));
    await expect(shared.get()).rejects.toBe(original);
    await expect(shared.get()).rejects.toBe(original);
    await expect(shared.dispose(fakeInfo())).rejects.toThrow(/open the shared project source/);
  });

  test('dispose deletes the project and closes the context', async () => {
    const f = fake({ pending: ['proj1'] });
    const shared = sharedOver(f);
    await shared.get();
    await shared.dispose(fakeInfo());
    expect(f.removed).toEqual(['proj1']);
    expect(f.closed).toBe(true);
  });

  test('a failed delete fails dispose and still closes the context', async () => {
    const f = fake({ pending: ['proj1'], failRemove: true });
    const shared = sharedOver(f);
    await shared.get();
    await expect(shared.dispose(fakeInfo())).rejects.toThrow(/delete shared project proj1/);
    expect(f.closed).toBe(true);
  });

  test('dispose without any use opens nothing', async () => {
    const f = fake();
    const shared = sharedOver(f);
    await shared.dispose(fakeInfo());
    expect(f.opens).toBe(0);
    expect(f.closed).toBe(false);
  });
});

test.describe('sharedEmptyDind fixture', () => {
  test('asking for the fixture creates nothing and needs no Engine until get() is called', ({
    sharedEmptyDind,
  }) => {
    expect(sharedEmptyDind).toBeInstanceOf(SharedProject);
  });
});
