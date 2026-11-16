import type { EngineApi } from '@/clients/engine-api';
import type { TaskSnapshot } from '@/types';
import { expectStatus } from '@/helpers/expect-status';
import { writeSiteFiles, type SiteFileMap } from '@/helpers/site-files';

const SOURCE = '/project/source';
const ARCHIVE = '/project/source.zip';

/**
 * Replaces the project's deploy source with `files` and starts a deploy from it. Answers
 * the raw 202 body, so a caller can watch the site while the deploy runs.
 */
export async function startArchiveDeploy(
  api: EngineApi,
  username: string,
  files: SiteFileMap
): Promise<unknown> {
  await removeIfPresent(api, username, SOURCE);
  await writeSiteFiles(api, username, SOURCE, files);
  await removeIfPresent(api, username, ARCHIVE);
  await api.zipFiles(username, ARCHIVE, SOURCE, true);
  const started = await api.deployArchiveRaw(username, { zip_path: ARCHIVE });
  await expectStatus(started, 202, JSON.stringify(started.body));
  return started.body;
}

/** {@link startArchiveDeploy}, then waits for the deploy task and throws unless it completed. */
export async function deployArchive(
  api: EngineApi,
  username: string,
  files: SiteFileMap
): Promise<TaskSnapshot> {
  const body = await startArchiveDeploy(api, username, files);
  return api.followDeployTask(body, `deploy-archive of ${username}`);
}

async function removeIfPresent(api: EngineApi, username: string, path: string): Promise<void> {
  if ((await api.fileExists(username, path)).exists) {
    await api.removeFile(username, path, true);
  }
}
