import type { EngineApi } from '@/clients/engine-api';

export interface UidGidInfo {
  uid: number;
  gid: number;
}

export function parseUidGid(stat: { uid: number | string; gid: number | string }): UidGidInfo {
  return {
    uid: Number(stat.uid),
    gid: Number(stat.gid),
  };
}

export async function getUserUidGid(api: EngineApi, username: string): Promise<UidGidInfo> {
  const user = await api.getUser(username);
  const data = (user?.data ?? {}) as unknown as {
    details?: { UID?: unknown; GID?: unknown };
    config?: { UID?: unknown; GID?: unknown };
    uid?: unknown;
    UID?: unknown;
    gid?: unknown;
    GID?: unknown;
  };

  const uid = Number(data.details?.UID ?? data.config?.UID ?? data.uid ?? data.UID);
  const gid = Number(data.details?.GID ?? data.config?.GID ?? data.gid ?? data.GID);

  if (!Number.isFinite(uid) || !Number.isFinite(gid)) {
    throw new Error(`Unable to determine UID/GID for user ${username}`);
  }

  return { uid, gid };
}
