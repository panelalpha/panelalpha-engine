import { expect, test } from '@/fixtures/test-options';
import { bandwidthSeriesSchema, visitorBreakdownSchema, visitorOverviewSchema } from '@/schemas';
import { validateParsedApiResponse } from '@/helpers/validate-parsed-response';

/**
 * Transfer and visitor stats read from AWStats. Missing logs are zeros and an
 * empty series, not an error — that is the contract BandwidthApiTest and
 * VisitorsApiTest pin in core/.
 */
const RANGE = 'start=2026-09-01&end=2026-09-22&group_by=day';
const VISITOR_RANGE = 'start=2026-09-01&end=2026-09-22';

test.describe('usage statistics', () => {
  test('project usage includes this month of transfer', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    const usage = await api.getUserUsage(user.username);
    expect(usage.bandwidth.usage).toBeGreaterThanOrEqual(0);
    expect(usage.bandwidth.maximum === null || typeof usage.bandwidth.maximum === 'number').toBe(
      true
    );
  });

  test('bandwidth series is a map of dates to bytes', async ({ api, userFactory }) => {
    const user = await userFactory.createSimpleUser();
    const project = await api.getProjectBandwidthRaw(user.username, RANGE);
    expect(project.status).toBe(200);
    validateParsedApiResponse(project.body, bandwidthSeriesSchema);

    const domain = await api.getDomainBandwidthRaw(user.username, user.domain, RANGE);
    expect(domain.status).toBe(200);
    validateParsedApiResponse(domain.body, bandwidthSeriesSchema);
  });

  test('visitor overview and a pages breakdown answer for the main domain', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const overview = await api.getDomainVisitorsRaw(user.username, user.domain, VISITOR_RANGE);
    expect(overview.status).toBe(200);
    const parsed = validateParsedApiResponse(overview.body, visitorOverviewSchema);
    expect(parsed.unique).toBeGreaterThanOrEqual(0);
    expect(parsed.total).toBeGreaterThanOrEqual(0);

    const pages = await api.getDomainVisitorBreakdownRaw(
      user.username,
      user.domain,
      'pages',
      VISITOR_RANGE
    );
    expect(pages.status).toBe(200);
    validateParsedApiResponse(pages.body, visitorBreakdownSchema);
  });

  test('a series without dates is a 422 and an unknown domain is a 404', async ({
    api,
    userFactory,
  }) => {
    const user = await userFactory.createSimpleUser();
    const missingDates = await api.getProjectBandwidthRaw(user.username);
    expect(missingDates.status).toBe(422);

    const badGroup = await api.getProjectBandwidthRaw(
      user.username,
      'start=2026-09-01&end=2026-09-22&group_by=week'
    );
    expect(badGroup.status).toBe(422);

    const unknownDomain = await api.getDomainVisitorsRaw(
      user.username,
      'no-such.example',
      VISITOR_RANGE
    );
    expect(unknownDomain.status).toBe(404);

    const unknownUser = await api.getProjectBandwidthRaw('nosuchuserzz', RANGE);
    expect(unknownUser.status).toBe(404);
  });
});
