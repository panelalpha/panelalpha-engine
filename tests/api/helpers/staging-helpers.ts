import { waitForCondition } from '@/helpers/retry';

interface UserLookup {
  getUserRaw: (username: string) => Promise<{ status: number; body: unknown }>;
}

export function stagingUserPayload(body: unknown): {
  username?: string;
  status?: string;
  staging_of?: string;
} {
  if (body === null || typeof body !== 'object' || !('data' in body)) {
    return {};
  }
  const data = (body as { data?: unknown }).data;
  if (data === null || typeof data !== 'object') {
    return {};
  }
  const record = data as Record<string, unknown>;
  return {
    username: typeof record.username === 'string' ? record.username : undefined,
    status: typeof record.status === 'string' ? record.status : undefined,
    staging_of: typeof record.staging_of === 'string' ? record.staging_of : undefined,
  };
}

export function stagingPushPhase(body: unknown): string | undefined {
  if (body === null || typeof body !== 'object' || !('data' in body)) {
    return undefined;
  }
  const data = (body as { data?: { details?: { async_status?: { push?: unknown } } } }).data;
  const phase = data?.details?.async_status?.push;
  return typeof phase === 'string' ? phase : undefined;
}

/**
 * Polls until the staging project is active.
 *
 * HTTP 404 is terminal: CreateStaging deletes the dest row in `failed()`, so
 * waiting out the 180s budget only hides that the job already died.
 */
export async function waitUntilStagingActive(
  api: UserLookup,
  stagingUsername: string
): Promise<void> {
  await waitForCondition(
    async () => {
      const last = await api.getUserRaw(stagingUsername);
      if (last.status === 404) {
        throw new Error(
          `Staging user ${stagingUsername} disappeared (HTTP 404). CreateStaging likely failed and deleted it.`
        );
      }
      return stagingUserPayload(last.body).status === 'active';
    },
    {
      timeout: 180_000,
      interval: 2_000,
      message: 'Staging user did not become active',
      describeLast: async () => {
        const last = await api.getUserRaw(stagingUsername);
        const payload = stagingUserPayload(last.body);
        return `http=${last.status} status=${payload.status ?? 'missing'}`;
      },
    }
  );
}

/** The error the Engine stored on the target when a push failed (`details.error`). */
export function stagingPushError(body: unknown): string | undefined {
  if (body === null || typeof body !== 'object' || !('data' in body)) {
    return undefined;
  }
  const data = (body as { data?: { details?: { error?: unknown } } }).data;
  const error = data?.details?.error;
  return typeof error === 'string' && error.length > 0 ? error : undefined;
}
