import { expect, test } from '@/fixtures/test-options';
import {
  clearSetupState,
  readSetupState,
  setupStateUsers,
  refreshLease,
  runIdentity,
  writeLease,
  writeSetupState,
} from '@/fixtures/helper/setup-state';
import { expectStatus } from '@/helpers/expect-status';
import { recoverOrphanedResources } from '@/helpers/resource-registry';
import { cleanupStaleTestUsers, recordMatchesUsername } from '@/helpers/test-user-cleanup';
import { randomPassword, randomUsername } from '@/helpers/random';
import { delay, waitForCondition } from '@/helpers/retry';
import { taskIdFromBody, waitForTask } from '@/helpers/task-helpers';
import {
  getWebserverInfo,
  getWebserverPropagationDelay,
  waitForSiteHttpReady,
} from '@/helpers/webserver-helpers';
import { parallelismFromEnv } from '@/config/parallelism';
import type { SetupTestData, UserCredentials } from '@/types/user.types';
import {
  ipv4FromPanelAlphaDirectZone,
  isThirdPartyIpZone,
  onlineLabel,
} from '@/config/site-domain';

/**
 * Provisions the one shared test user the whole suite runs against and writes it
 * to `.playwright/state/setup.json`, which the `api` project reads through the
 * `setupUser` fixture.
 *
 * Created with `tunnel: none` so the name is on panelalpha.direct (TLS and
 * FTP/SFTP on this engine). The default DomainPlan path — panelalpha.online,
 * TLS at the proxy — is covered by `tests/tunnels/panelalpha-online.spec.ts`.
 * A rerun reuses the user in that file as long as the engine still knows it and
 * its site answers 2xx, so iterating on a single spec does not pay for a
 * WordPress install every time. Delete the state file to force a fresh user.
 *
 * When the run is split (`TEST_PARALLEL`), one such user is provisioned per parallel
 * worker, all at once, and the state file lists them; each worker picks its own by
 * `parallelIndex` in the `setupUser` fixture.
 */

const CREATE_MAX_ATTEMPTS = 5;

test('provision shared test user with WordPress', async ({
  api,
  authedRequest,
  anonymousRequest,
  userFactory,
  wordpressFactory,
  settings,
}) => {
  // Throws with a clear message when neither DOMAIN nor API_BASE_URL yields one.
  const baseDomain = settings.requireDomain();

  const { slug: webserver } = await getWebserverInfo(api);

  await test.step("the addon parent is this engine's zone", async () => {
    const engineAddress = (await api.getSystemInfo()).data.default_ipv4;
    const zoneAddress = ipv4FromPanelAlphaDirectZone(baseDomain);

    if (!zoneAddress || !engineAddress) {
      return;
    }

    expect(
      zoneAddress,
      `Addon names hang off ${baseDomain}, but this engine serves sites on ` +
        `${engineAddress}. Set DOMAIN to the engine's panelalpha.direct zone if you ` +
        'must override.'
    ).toBe(engineAddress);
  });

  const siteReadyTimeout = Math.max(
    90_000,
    settings.timing.propagationDelay * 6,
    getWebserverPropagationDelay(webserver) * 4
  );

  /** The engine registers a user asynchronously; poll until GET /projects/<name> answers. */
  const waitForUserVisible = async (name: string): Promise<void> => {
    let lastStatus: number | undefined;

    await waitForCondition(
      async () => {
        const response = await authedRequest.get(`projects/${name}`);
        lastStatus = response.status();
        return response.ok();
      },
      {
        timeout: settings.timing.propagationDelay,
        interval: 500,
        message: `Project ${name} did not become visible`,
        describeLast: () => `GET projects/${name} -> HTTP ${lastStatus ?? 'none'}`,
      }
    );
  };

  const userCount = parallelismFromEnv().apiWorkers;
  const suffix = (index: number): string => (userCount > 1 ? ` ${index + 1}/${userCount}` : '');

  /** A user from a previous run, when the engine still knows it and its site still answers. */
  const reuse = async (
    candidate: SetupTestData | undefined
  ): Promise<SetupTestData | undefined> => {
    if (!candidate?.url) {
      return undefined;
    }

    const raw = await api.getUserRaw(candidate.username);
    const known = raw.status !== 404;
    if (known) {
      await expectStatus(raw, 200, `Cached setup user "${candidate.username}" cannot be reused`);
      expect(
        recordMatchesUsername(raw.body, candidate.username),
        `GET projects/${candidate.username} answered 200 with a different record`
      ).toBe(true);
    }
    if (known && onlineLabel(candidate.domain)) {
      console.warn(
        `[setup] cached user "${candidate.username}" is on panelalpha.online — recreating so www/FTP/permalinks hit the engine`
      );
      return undefined;
    }
    if (known) {
      try {
        await waitForSiteHttpReady(anonymousRequest, candidate.url, {
          timeout: siteReadyTimeout,
          interval: 2_000,
          requireSuccess: true,
        });
        return candidate;
      } catch {
        console.warn(`[setup] cached user "${candidate.username}" is not serving — recreating`);
      }
    }
    return undefined;
  };

  const state = readSetupState();
  const previous = state ? setupStateUsers(state) : [];
  const reusedUsers = await Promise.all(
    Array.from({ length: userCount }, (_, index) =>
      test.step(`reuse user from a previous run, if still usable${suffix(index)}`, () =>
        reuse(previous[index]))
    )
  );
  const reusedNames = reusedUsers.flatMap((user) => (user ? [user.username] : []));

  if (state && reusedUsers.every((user) => user !== undefined)) {
    const users = reusedUsers;
    writeLease(reusedNames);
    writeSetupState({
      ...state,
      user: users[0],
      ...(userCount > 1 ? { users } : { users: undefined }),
      ...runIdentity(),
    });
    refreshLease();
    test
      .info()
      .annotations.push({ type: 'setup', description: `reused ${reusedNames.join(', ')}` });
    return;
  }

  if (state) {
    writeLease(reusedNames);
    clearSetupState();
  } else {
    writeLease();
  }

  await test.step('remove leftover users from earlier runs', async () => {
    const recovery = await recoverOrphanedResources(
      {
        exists: async (username) => (await api.getUserRaw(username)).status !== 404,
        remove: (username) => userFactory.deleteUser(username),
      },
      { keepUsernames: reusedNames }
    );
    for (const item of recovery.recovered) {
      console.log(`[recovery] ${item.username} (run ${item.runId}): ${item.outcome}`);
    }
    for (const item of recovery.kept) {
      console.log(`[recovery] kept ${item.username} (run ${item.runId}): ${item.reason}`);
    }
    console.log(
      `[setup] recovered ${recovery.recovered.length} open project(s) from earlier runs, kept ${recovery.kept.length}`
    );
    const { removed, kept } = await cleanupStaleTestUsers(api, userFactory, {
      keepUsernames: reusedNames,
    });
    console.log(`[setup] removed ${removed.length} stale test user(s), kept ${kept.length}`);
  });

  const provision = async (index: number): Promise<SetupTestData> => {
    const { username, domain } = await test.step(`create user${suffix(index)}`, async () => {
      for (let attempt = 1; attempt <= CREATE_MAX_ATTEMPTS; attempt++) {
        const candidate = randomUsername();

        const response = await authedRequest.post('projects', {
          data: { username: candidate, tunnel: 'none' },
        });
        // Canonical create is 202 + a task. 201 is the older synchronous path.
        if (response.status() === 202) {
          const body: unknown = await response.json();
          const taskId = taskIdFromBody(body);
          if (taskId === undefined) {
            throw new Error(
              `User creation returned 202 without a task id: ${JSON.stringify(body)}`
            );
          }
          const task = await waitForTask(api, taskId, { timeout: settings.timing.deployTimeout });
          if (task.status !== 'completed') {
            throw new Error(`User creation task ${taskId} ended ${task.status}`);
          }
          const assigned = (await api.getUser(candidate)).data.domain;
          return { username: candidate, domain: assigned };
        }
        if (response.status() === 201) {
          const assigned = (await api.getUser(candidate)).data.domain;
          return { username: candidate, domain: assigned };
        }

        const body = await response.text();
        const servicesSlow =
          response.status() === 422 && /waited too long for all services to start/i.test(body);
        if (!servicesSlow || attempt === CREATE_MAX_ATTEMPTS) {
          throw new Error(`User creation failed with ${response.status()}: ${body}`);
        }

        const retryDelay = Math.max(getWebserverPropagationDelay(webserver) * 2, 15_000);
        console.warn(
          `[setup] container services not ready (attempt ${attempt}/${CREATE_MAX_ATTEMPTS}, ` +
            `webserver=${webserver}) — retrying in ${retryDelay / 1000}s`
        );
        await delay(retryDelay);
      }
      throw new Error(`User creation did not succeed within ${CREATE_MAX_ATTEMPTS} attempts`);
    });

    writeLease([username]);
    await waitForUserVisible(username);
    expect(
      isThirdPartyIpZone(domain),
      `Engine named the project ${domain}; sslip.io leftovers come from default_wildcard_domain. ` +
        'Clear that setting so DomainPlan can use panelalpha.online / panelalpha.direct.'
    ).toBe(false);

    const credentials = await test.step(`provision database${suffix(index)}`, async () => {
      const database = `${username}_wp`;
      const mysqlPassword = randomPassword(16);
      const mysqlUsername = `${username}_${username}`;

      await api.createMySqlDatabase(username, database);
      await api.createMySqlUser(username, username, mysqlPassword);
      await api.grantPrivileges(
        username,
        mysqlUsername,
        database,
        settings.mysql.defaultPrivileges
      );

      return {
        username,
        domain,
        database,
        mysqlHost: settings.mysql.host,
        mysqlUsername,
        mysqlPassword,
      } satisfies UserCredentials;
    });

    const wordpress = await wordpressFactory.installWordPress(credentials);
    const user: SetupTestData = { ...credentials, ...wordpress };

    expect(user.wpPath, 'WordPress install returned no path').toBeTruthy();
    expect(user.url, 'WordPress install returned no URL').toBeTruthy();

    await waitForUserVisible(username);
    await waitForSiteHttpReady(anonymousRequest, user.url, {
      timeout: siteReadyTimeout,
      interval: 2_000,
      requireSuccess: true,
    });
    return user;
  };

  // Users that could be reused stay; the missing ones are created together.
  const users = await Promise.all(
    reusedUsers.map(async (existing, index) => existing ?? (await provision(index)))
  );

  writeSetupState({
    user: users[0],
    ...(userCount > 1 ? { users } : {}),
    webserver,
    createdAt: Date.now(),
    ...runIdentity(),
  });
  writeLease(users.map((user) => user.username));
  refreshLease();
  for (const user of users) {
    const source = (await api.getUser(user.username)).data.details.domain?.source;
    console.log(
      `[setup] ${reusedNames.includes(user.username) ? 'kept' : 'created'} ${user.username} (${user.domain}` +
        `${source ? `, source ${source}` : ''}) on ${webserver}`
    );
  }
});
