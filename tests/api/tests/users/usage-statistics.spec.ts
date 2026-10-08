import { expect, test } from '@/fixtures/test-options';
import { bandwidthSeriesSchema, visitorBreakdownSchema, visitorOverviewSchema } from '@/schemas';
import { expectStatus } from '@/helpers/expect-status';
import { sharedProject } from '@/helpers/shared-project';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

/**
 * Transfer and visitor stats read from AWStats. Missing logs are zeros and an
 * empty series, not an error — that is the contract BandwidthApiTest and
 * VisitorsApiTest pin in core/.
 */
const RANGE = 'start=2026-09-01&end=2026-09-22&group_by=day';
const VISITOR_RANGE = 'start=2026-09-01&end=2026-09-22';

test.describe('usage statistics', () => {
  // Reads and refused requests only, on a project nobody sends traffic to, so its logs stay empty.
  const shared = sharedProject('simple');
  test.afterAll(() => shared.dispose());

  test('project usage includes this month of transfer', async ({ api }) => {
    const user = await shared.get();
    const usage = await api.getUserUsage(user.username);
    expect(usage.bandwidth.usage).toBeGreaterThanOrEqual(0);
    expect(
      usage.bandwidth.maximum === null || typeof usage.bandwidth.maximum === 'number',
      'bandwidth.maximum is null or a number'
    ).toBe(true);
  });

  test('bandwidth series is a map of dates to bytes', async ({ api }) => {
    const user = await shared.get();
    const project = await api.getProjectBandwidthRaw(user.username, RANGE);
    await expectStatus(project, 200);
    validateParsedApiResponse(project.body, bandwidthSeriesSchema);

    const domain = await api.getDomainBandwidthRaw(user.username, user.domain, RANGE);
    await expectStatus(domain, 200);
    validateParsedApiResponse(domain.body, bandwidthSeriesSchema);
  });

  test('visitor overview and a pages breakdown answer for the main domain', async ({ api }) => {
    const user = await shared.get();
    const overview = await api.getDomainVisitorsRaw(user.username, user.domain, VISITOR_RANGE);
    await expectStatus(overview, 200);
    const parsed = validateParsedApiResponse(overview.body, visitorOverviewSchema);
    expect(parsed.unique).toBeGreaterThanOrEqual(0);
    expect(parsed.total).toBeGreaterThanOrEqual(0);

    const pages = await api.getDomainVisitorBreakdownRaw(
      user.username,
      user.domain,
      'pages',
      VISITOR_RANGE
    );
    await expectStatus(pages, 200);
    validateParsedApiResponse(pages.body, visitorBreakdownSchema);
  });

  test('a series without dates is a 422 and an unknown domain is a 404', async ({ api }) => {
    const user = await shared.get();
    await test.step('a series without dates is refused', async () => {
      const missingDates = await api.getProjectBandwidthRaw(user.username);
      await expectStatus(missingDates, 422);
    });

    await test.step('an unsupported group_by is refused', async () => {
      const badGroup = await api.getProjectBandwidthRaw(
        user.username,
        'start=2026-09-01&end=2026-09-22&group_by=week'
      );
      await expectStatus(badGroup, 422);
    });

    await test.step('an unknown domain is not found', async () => {
      const unknownDomain = await api.getDomainVisitorsRaw(
        user.username,
        'no-such.example',
        VISITOR_RANGE
      );
      await expectStatus(unknownDomain, 404);
    });

    await test.step('an unknown project is not found', async () => {
      const unknownUser = await api.getProjectBandwidthRaw('nosuchuserzz', RANGE);
      await expectStatus(unknownUser, 404);
    });
  });
});
