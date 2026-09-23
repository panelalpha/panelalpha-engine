import type { EngineApi } from '@/clients/engine-api';

/** Relative path → file contents. Directories are created as needed. */
export type SiteFileMap = Record<string, string>;

/**
 * Writes `files` under `root` in the project home (paths like `/probe/static`).
 * Nested paths mkdir their parents first.
 */
export async function writeSiteFiles(
  api: EngineApi,
  username: string,
  root: string,
  files: SiteFileMap
): Promise<void> {
  const base = root.replace(/\/+$/, '');
  await api.createDirectory(username, base, true);

  const directories = new Set<string>([base]);
  for (const relative of Object.keys(files)) {
    const full = `${base}/${relative.replace(/^\/+/, '')}`;
    const dir = full.slice(0, full.lastIndexOf('/'));
    if (dir.length > 0) {
      directories.add(dir);
    }
  }

  const ordered = [...directories].sort((a, b) => a.length - b.length);
  for (const directory of ordered) {
    await api.createDirectory(username, directory, true);
  }

  for (const [relative, contents] of Object.entries(files)) {
    const full = `${base}/${relative.replace(/^\/+/, '')}`;
    await api.putFileContents(username, full, contents);
  }
}

/** Home-relative subdirectory for POST /source/inspect `subdirectory`. */
export function inspectSubdirectory(root: string): string {
  return root.replace(/^\/+/, '').replace(/\/+$/, '');
}
