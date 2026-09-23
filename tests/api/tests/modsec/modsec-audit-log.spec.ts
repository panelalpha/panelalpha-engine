import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { auditLogFiles, modsecUnavailableReason } from '@/helpers/modsec-helpers';

test.describe('ModSecurity audit log', () => {
  test.beforeEach(async ({ api, anonymousRequest, setupUser }) => {
    const reason = await modsecUnavailableReason(api, anonymousRequest, setupUser.url);
    test.skip(Boolean(reason), reason ?? '');
  });

  test('every listed file has a name and a path', async ({ api }) => {
    const { data } = await api.listModSecurityAuditLogFiles();
    expect(Array.isArray(data)).toBe(true);

    for (const file of data) {
      expect(typeof file.file).toBe('string');
      expect(typeof file.path).toBe('string');
    }
  });

  test('a listed file can be downloaded', async ({ api, anonymousRequest, setupUser }) => {
    const data = await auditLogFiles(api, anonymousRequest, setupUser.url);
    test.skip(
      data.length === 0,
      'The engine lists no audit log files even after a request the WAF should flag.'
    );

    expect((await api.downloadModSecurityAuditLog(data[0].file)).status).toBe(200);
  });

  test('a listed file can be tailed', async ({ api, anonymousRequest, setupUser }) => {
    const data = await auditLogFiles(api, anonymousRequest, setupUser.url);
    test.skip(
      data.length === 0,
      'The engine lists no audit log files even after a request the WAF should flag.'
    );

    const tail = await api.tailModSecurityAuditLog(data[0].file);
    expect(tail.status).toBe(200);
    expect(Array.isArray(tail.data)).toBe(true);
  });

  test('downloading an unknown file returns 404', async ({ api }) => {
    expect((await api.downloadModSecurityAuditLog('non-existent-audit-log-12345.log')).status).toBe(
      404
    );
  });

  test('tailing an unknown file returns 404', async ({ api }) => {
    expect((await api.tailModSecurityAuditLog('non-existent-audit-log-12345.log')).status).toBe(
      404
    );
  });

  /**
   * The filename becomes a path on the engine host, so a name that escapes the
   * log directory or carries shell syntax must not be honoured.
   */
  const dangerousFilenames = [
    '../../../etc/passwd',
    'file;rm -rf /',
    'file<script>alert(1)</script>',
    'file%00null.log',
  ];

  for (const filename of dangerousFilenames) {
    for (const route of ['download', 'tail'] as const) {
      test(
        `${route} refuses the filename ${JSON.stringify(filename)}`,
        {
          tag: ['@security'],
        },
        async ({ authedRequest }) => {
          const path =
            `modsec/audit-log/files/${encodeURIComponent(filename)}` +
            (route === 'tail' ? '/tail' : '');

          const response = await authedRequest.get(path);
          const status = response.status();

          // Some stacks answer 200 with their own "page not found" body rather
          // than a JSON error, which is still a refusal.
          if (status === 200) {
            expect(await response.text()).toMatch(/page not found/i);
            return;
          }

          expectOneOf(status, [400, 404, 422], `${route} accepted ${filename}`);
        }
      );
    }
  }
});
