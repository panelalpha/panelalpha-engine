import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';

test.describe('bug reports', () => {
  test('an incomplete payload is 422', async ({ api }) => {
    const response = await api.createBugReportRaw({
      title: 'x',
      description: 'y',
    });
    expectOneOf(response.status, [400, 422]);
  });

  test('an unknown project is 404', async ({ api }) => {
    const response = await api.createBugReportRaw({
      project: 'nosuchuser999',
      title: 'The site answers 502 after a green deploy',
      description: 'It finishes green and then 502s until I restart the container.',
      attach_health: false,
    });
    expectOneOf(response.status, [404, 409, 503]);
  });

  test('a report against a real project is accepted or refused honestly', async ({
    api,
    setupUser,
  }) => {
    // A queued report is shipped to production monitoring like a customer's.
    test.skip(
      process.env.ALLOW_BUG_REPORT_SHIP !== '1',
      'Set ALLOW_BUG_REPORT_SHIP=1 to file a real bug report. A queued report reaches production monitoring.'
    );
    const response = await api.createBugReportRaw({
      project: setupUser.username,
      title: '[api-test] The site answers 502 after a green deploy',
      description: 'It finishes green and then 502s until I restart the container.',
      attach_health: false,
      attach_log: false,
    });
    expectOneOf(response.status, [201, 409, 503]);
    if (response.status === 201) {
      const body = response.body as { data?: { queued?: boolean; id?: string } };
      expect(body.data?.queued).toBe(true);
      expect(typeof body.data?.id).toBe('string');
    }
  });
});
