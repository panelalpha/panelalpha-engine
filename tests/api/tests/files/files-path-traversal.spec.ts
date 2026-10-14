import { test } from '@/fixtures/test-options';
import type { EngineApi } from '@/clients/engine-api';
import { expectOneOf } from '@/helpers/expect-one-of';

/**
 * Every file endpoint takes a path from the caller, and every one of them has to
 * confine that path to the user's home directory. A single endpoint that does
 * not is enough to read `/etc/passwd` or overwrite another tenant's files, so
 * each is probed with the same escapes.
 */

const REJECTED = [400, 403, 422] as const;

interface TraversalProbe {
  /** Sends the malicious request and returns the HTTP status. */
  send: (api: EngineApi, username: string) => Promise<number>;
  /**
   * Statuses that count as "not exploited".
   *
   * Read-only probes may answer 200 with `exists: false`, which is a refusal to
   * leak rather than a leak; anything that writes must reject outright.
   */
  accepted?: readonly number[];
}

const PROBES: Record<string, TraversalProbe> = {
  'exists, with a relative escape': {
    send: async (api, username) =>
      (
        await api.get(
          `projects/${username}/files/exists?path=${encodeURIComponent('../../../../etc/passwd')}`
        )
      ).status(),
  },
  'exists, with an absolute path outside the home': {
    send: async (api, username) =>
      (
        await api.get(`projects/${username}/files/exists?path=${encodeURIComponent('/etc/passwd')}`)
      ).status(),
    // A bare "does this exist" may legitimately answer 200 with exists: false.
    accepted: [200, 400, 403, 422],
  },
  'stat, with a relative escape': {
    send: async (api, username) =>
      (
        await api.get(
          `projects/${username}/files/stat?path=${encodeURIComponent('../..//etc/passwd')}`
        )
      ).status(),
  },
  'list, with a relative escape': {
    send: async (api, username) =>
      (
        await api.get(`projects/${username}/files/list?path=${encodeURIComponent('../..//etc')}`)
      ).status(),
  },
  'put-contents, with a relative escape': {
    send: async (api, username) =>
      (
        await api.put(`projects/${username}/files/put-contents`, {
          data: { path: '../../etc/passwd', contents: 'blocked' },
        })
      ).status(),
  },
  'put-contents, with a percent-encoded escape': {
    send: async (api, username) =>
      (
        await api.put(`projects/${username}/files/put-contents`, {
          data: { path: '..%2f..%2fetc%2fpasswd', contents: 'blocked' },
        })
      ).status(),
  },
  'put-contents, with an absolute path outside the home': {
    send: async (api, username) =>
      (
        await api.put(`projects/${username}/files/put-contents`, {
          data: { path: '/etc/passwd', contents: 'blocked' },
        })
      ).status(),
  },
  'copy, with escaping source and destination': {
    send: async (api, username) =>
      (
        await api.put(`projects/${username}/files/cp`, {
          data: { source_path: '../../etc/passwd', dest_path: '../../tmp/passwd' },
        })
      ).status(),
  },
  'move, with escaping source and destination': {
    send: async (api, username) =>
      (
        await api.put(`projects/${username}/files/mv`, {
          data: { source_path: '../../etc/passwd', dest_path: '../../tmp/passwd' },
        })
      ).status(),
  },
  'zip, with an escaping source': {
    send: async (api, username) =>
      (
        await api.post(`projects/${username}/files/zip`, {
          data: { zip_path: '/tmp/invalid.zip', path: '../../etc/passwd', skip_parents: true },
        })
      ).status(),
  },
  'unzip, into a destination outside the home': {
    send: async (api, username) =>
      (
        await api.post(`projects/${username}/files/unzip`, {
          data: { zip_path: '/etc/passwd', path: '/tmp' },
        })
      ).status(),
  },
};

test.describe('file path traversal', () => {
  for (const [label, probe] of Object.entries(PROBES)) {
    test(`${label} is refused`, { tag: ['@security'] }, async ({ api, setupUser }) => {
      const status = await probe.send(api, setupUser.username);
      expectOneOf(status, probe.accepted ?? REJECTED, `${label} was not refused`);
    });
  }

  test(
    'upload, to a destination outside the home is refused',
    {
      tag: ['@security'],
    },
    async ({ authedRequest, setupUser }) => {
      const response = await authedRequest.post(`projects/${setupUser.username}/files/upload`, {
        multipart: {
          path: '../../etc',
          file: {
            name: 'passwd',
            mimeType: 'text/plain',
            buffer: Buffer.from('malicious content', 'utf-8'),
          },
        },
      });

      expectOneOf(response.status(), REJECTED, 'an upload escaped the home directory');
    }
  );
});
