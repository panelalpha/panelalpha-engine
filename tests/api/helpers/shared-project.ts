import { request } from '@playwright/test';
import { createApiTransport } from '@/clients/api-transport';
import { EngineApi } from '@/clients/engine-api';
import { getSettings } from '@/config/settings';
import { CleanupSteps, type CleanupTestInfo } from '@/helpers/cleanup-step';
import { withRequestMethods } from '@/helpers/request-method';
import { UserFactory, type CreateUserOptions } from '@/test-data/factories/user.factory';

export type SharedProjectKind = 'simple' | 'dind';

export interface SharedProjectUser {
  username: string;
  domain: string;
}

/** What a {@link SharedProject} needs from the Engine; a unit test passes a stand-in. */
export interface SharedProjectSource {
  /** Creates the project. Null only when the Engine explicitly refuses the template. */
  provision(): Promise<SharedProjectUser | null>;
  /** Projects made through this source that nothing has deleted yet, also after a failed create. */
  pending(): string[];
  remove(username: string): Promise<void>;
  close(): Promise<void>;
}

/** `get()` is nullable only for DinD, where the Engine may refuse the template. */
export type SharedProjectValue<K extends SharedProjectKind> = K extends 'dind'
  ? SharedProjectUser | null
  : SharedProjectUser;

/**
 * One project shared by the read-only tests of a file.
 *
 * ```ts
 * const shared = sharedProject('dind');
 * test.afterAll(() => shared.dispose());
 *
 * test('reads something', async ({ api }) => {
 *   const user = await shared.get();
 *   skipUnless(user, 'DinD is not available on this engine.');
 *   ...
 * });
 * ```
 *
 * The project is created by the first test that asks for it, once per worker, through a
 * request context and a UserFactory of its own: the `api` and `userFactory` fixtures are
 * test-scoped and delete their projects when the test ends. A failed provisioning is
 * remembered, so every dependent test fails with the original error instead of skipping.
 * `dispose` deletes the project; a delete that fails fails the hook.
 *
 * Only tests that leave the project as they found it may use it.
 */
export class SharedProject<K extends SharedProjectKind = SharedProjectKind> {
  private source?: Promise<SharedProjectSource>;
  private provisioned?: Promise<SharedProjectUser | null>;

  constructor(private readonly open: () => Promise<SharedProjectSource>) {}

  /** The shared project, created on the first call. Rejects with the same error every time. */
  get(): Promise<SharedProjectValue<K>> {
    this.provisioned ??= this.provision();
    return this.provisioned as Promise<SharedProjectValue<K>>;
  }

  /** Deletes the project, and whatever a failed create left behind. A no-op when never used. */
  async dispose(info?: CleanupTestInfo): Promise<void> {
    if (!this.source) {
      return;
    }
    const cleanup = new CleanupSteps(info);
    await this.provisioned?.catch(() => undefined);

    let source: SharedProjectSource | undefined;
    await cleanup.run('open the shared project source', async () => {
      source = await this.source;
    });
    if (source) {
      const opened = source;
      for (const username of opened.pending()) {
        await cleanup.run(`delete shared project ${username}`, () => opened.remove(username));
      }
      await cleanup.run('close the shared project request context', () => opened.close());
    }
    this.source = undefined;
    this.provisioned = undefined;
    await cleanup.finish();
  }

  private async provision(): Promise<SharedProjectUser | null> {
    this.source = this.open();
    return (await this.source).provision();
  }
}

/** A shared project on the Engine named by the suite's settings and API token. */
export function sharedProject<K extends SharedProjectKind>(
  kind: K,
  options: Pick<CreateUserOptions, 'tunnel'> = {}
): SharedProject<K> {
  return new SharedProject<K>(() => openEngineSource(kind, options));
}

async function openEngineSource(
  kind: SharedProjectKind,
  options: Pick<CreateUserOptions, 'tunnel'>
): Promise<SharedProjectSource> {
  const settings = getSettings();
  const context = await request.newContext({
    baseURL: settings.apiBaseUrl,
    ignoreHTTPSErrors: true,
    extraHTTPHeaders: {
      Accept: 'application/json',
      ...(process.env.API_TOKEN ? { Authorization: `Bearer ${process.env.API_TOKEN}` } : {}),
    },
  });
  const factory = new UserFactory(new EngineApi(createApiTransport(withRequestMethods(context))));
  return {
    provision: () =>
      kind === 'dind' ? factory.createDindUser(options) : factory.createSimpleUser(options),
    pending: () => factory.pendingCleanup,
    remove: (username) => factory.deleteUser(username),
    close: () => context.dispose(),
  };
}
