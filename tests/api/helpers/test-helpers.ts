import { test } from '@playwright/test';
import { onlineLabel } from '@/config/site-domain';
import type { UserFactory } from '@/test-data/factories';

/** Skips the current test with a consistent `[skip]` prefix in reports. */
export function skipTest(reason: string): void {
  test.skip(true, `[skip] ${reason}`);
}

/**
 * Skips when `value` is missing and narrows it for the rest of the test.
 *
 * `test.skip(!user, …)` does not count as a type guard, so callers that keep
 * using the value afterwards fail `tsc`. This does.
 */
export function skipUnless<T>(value: T, reason: string): asserts value is NonNullable<T> {
  if (value == null || value === false || value === '') {
    test.skip(true, reason);
  }
}

/** Skip when DomainPlan did not allocate a `*.panelalpha.online` name. */
export function skipUnlessOnline(domain: string): void {
  skipUnless(onlineLabel(domain), `PanelAlpha Online was not allocated (${domain}).`);
}

/**
 * Creates a throwaway user, runs `body` against it, and deletes it afterwards, also when
 * the body fails. Only PRESERVE_ON_FAILURE=1 outside CI keeps it; the `userFactory`
 * fixture then annotates the test with its name.
 */
export async function withTestUser<T>(
  userFactory: UserFactory,
  body: (user: { username: string; domain: string }) => Promise<T>
): Promise<T> {
  const user = await userFactory.createSimpleUser();
  try {
    return await body(user);
  } finally {
    await deleteUserUnlessTestFailed(userFactory, user.username);
  }
}

/**
 * Deletes a user, also after a failure (its state is attached first). A failed test keeps
 * it only under PRESERVE_ON_FAILURE=1 outside CI; the fixture then annotates it.
 */
export async function deleteUserUnlessTestFailed(
  userFactory: UserFactory,
  username: string
): Promise<void> {
  const info = test.info();
  if (info.status !== info.expectedStatus) {
    await userFactory.attachFailureDiagnostics(username);
    if (userFactory.shouldKeepAfterFailure(username)) {
      return;
    }
  }
  await userFactory.deleteUser(username);
}
