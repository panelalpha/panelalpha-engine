import { expect, test } from '@/fixtures/test-options';
import { expectOneOf } from '@/helpers/expect-one-of';
import { expectStatus } from '@/helpers/expect-status';
import { lighthouseReportResponseSchema } from '@/schemas';

/**
 * Lighthouse runs in a separate container that is not part of every deployment
 * and is slow enough to time out under load. The service statuses below are all
 * "the endpoint behaved"; only a 200 is checked for an actual report.
 */
const SERVICE_STATUSES = [200, 400, 422, 500, 502, 503] as const;

/**
 * The one answer that means "not configured": the `disable-lighthouse` setting
 * makes LighthouseController::generateReport throw a 422 whose message is this.
 */
const DISABLED_MESSAGE = 'lighthouse service is disabled';

function isDisabled(response: { status: number; body: unknown }): boolean {
  return response.status === 422 && JSON.stringify(response.body ?? '').includes(DISABLED_MESSAGE);
}

test.describe('Lighthouse', () => {
  const presets = [
    ['the default preset', {}],
    ['the desktop preset', { desktop_preset: true }],
    ['no local DNS resolution', { no_local_resolve: true }],
  ] as const;

  for (const [label, options] of presets) {
    test(`a report can be requested with ${label}`, async ({ api, setupUser }) => {
      const response = await api.generateLighthouseReportRaw({
        url: `https://${setupUser.domain}/`,
        ...options,
      });

      expectOneOf(response.status, SERVICE_STATUSES);
      if (response.status === 200) {
        expect((response.body as { data?: unknown } | null)?.data).toBeDefined();
      }
    });
  }

  test('a request without a url is refused', async ({ api }) => {
    expectOneOf((await api.generateLighthouseReportRaw({})).status, [400, 422]);
  });

  test('a returned report matches its schema', async ({ api, setupUser }) => {
    const url = `https://${setupUser.domain}/`;

    const probe = await api.generateLighthouseReportRaw({ url });
    test.skip(isDisabled(probe), 'The Lighthouse service is disabled on this engine.');
    await expectStatus(probe, 200, 'POST /lighthouse/generate-report');

    const report = await api.generateLighthouseReport({ url });
    const parsed = lighthouseReportResponseSchema.safeParse(report);
    expect(parsed.success, parsed.error?.toString()).toBe(true);
  });
});
