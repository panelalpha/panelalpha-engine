/**
 * What the leak check lets through.
 *
 * Two kinds of entry:
 *  - `users`: projects that legitimately outlive a run, plus everything owned
 *    by them (domains, databases, proxy rules, assigned IPs…). The shared setup
 *    users come from the setup state files, so they are not listed here.
 *  - `entries`: one section and key each. `key: '*'` matches every key in the
 *    section; a RegExp is tested against the key.
 */

export interface AllowEntry {
  section: string;
  key: string | RegExp;
  reason: string;
}

export interface AllowList {
  users: string[];
  entries: AllowEntry[];
}

/**
 * Classes the engine keeps on purpose. The API does not list docker images or
 * the prewarm cache, so no snapshot section carries them today; the entries
 * are here so a host-side collector adding those sections is not flagged for
 * growth the engine itself defends (DindAccountCleanup keeps infrastructure
 * images and the prewarm cache between runs).
 */
export const ALLOWED_CLASSES: readonly AllowEntry[] = [
  {
    section: 'docker-images',
    key: '*',
    reason:
      'Infrastructure images are kept by the engine on purpose; image growth alone is not a leak.',
  },
  {
    section: 'docker-cache',
    key: '*',
    reason: 'The prewarm cache is kept by the engine on purpose.',
  },
];

/** Allow list for a run, given the setup users read from the state files. */
export function buildAllowList(setupUsers: readonly string[] = []): AllowList {
  return {
    users: [...new Set(setupUsers.filter((user) => user.length > 0))],
    entries: [...ALLOWED_CLASSES],
  };
}

export function matchesEntry(entry: AllowEntry, section: string, key: string): boolean {
  if (entry.section !== section) {
    return false;
  }
  if (entry.key === '*') {
    return true;
  }
  return typeof entry.key === 'string' ? entry.key === key : entry.key.test(key);
}
