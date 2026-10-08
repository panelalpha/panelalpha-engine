import type { EngineApi } from '@/clients/engine-api';
import { expectStatus } from './expect-status';

/**
 * Whether proxy-rule management answers at all on this engine. Only 404 (the
 * route is not registered) is a definite absence; any other answer that is not
 * 200 throws.
 */
export async function isProxyRulesAvailable(api: EngineApi): Promise<boolean> {
  const response = await api.listProxyRulesRaw();
  if (response.status === 404) {
    return false;
  }
  await expectStatus(response, 200, 'GET /proxy-rules');
  return true;
}

/**
 * Whether container/deploy endpoints accept this user as a DinD project.
 *
 * The engine answers 403 for a project that is not DinD and 404 for a missing one;
 * only those two answers mean "no". Any other non-200 (401, 5xx, …) throws.
 */
export async function isDindUser(api: EngineApi, username: string): Promise<boolean> {
  const response = await api.listContainersRaw(username);
  if (response.status === 403 || response.status === 404) {
    return false;
  }
  await expectStatus(response, 200, `GET projects/${username}/containers`);
  return true;
}
