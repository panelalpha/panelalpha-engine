import { expect, test } from '@/fixtures/test-options';
import { bandwidthSeriesSchema, inspectPortsSchema, visitorOverviewSchema } from '@/schemas';
import {
  SUPPORTED_APPS,
  supportedAppSlug,
  type SupportedAppStack,
} from '@/test-data/static/supported-apps';

const REQUIRED_STACKS: readonly SupportedAppStack[] = [
  'php',
  'laravel',
  'python',
  'nodejs',
  'go',
  'java',
  'rust',
  'ruby',
];

test.describe('supported-app catalogue', () => {
  test('covers several apps from each stack', () => {
    expect(SUPPORTED_APPS.length).toBeGreaterThanOrEqual(40);
    expect(SUPPORTED_APPS.length).toBeLessThanOrEqual(50);
    const counts = new Map<SupportedAppStack, number>();
    for (const entry of SUPPORTED_APPS) {
      counts.set(entry.stack, (counts.get(entry.stack) ?? 0) + 1);
    }
    for (const stack of REQUIRED_STACKS) {
      expect(counts.get(stack) ?? 0, stack).toBeGreaterThan(0);
    }
    expect(counts.get('php')).toBeGreaterThanOrEqual(4);
    expect(counts.get('nodejs')).toBeGreaterThanOrEqual(4);
    expect(counts.get('python')).toBeGreaterThanOrEqual(3);
    expect(counts.get('go')).toBeGreaterThanOrEqual(3);
    expect(counts.get('laravel')).toBeGreaterThanOrEqual(2);
    expect(counts.get('java')).toBeGreaterThanOrEqual(2);
  });

  test('issue ids, slugs and repositories are unique', () => {
    const iids = SUPPORTED_APPS.map((entry) => entry.iid);
    const slugs = SUPPORTED_APPS.map((entry) => entry.slug);
    const repos = SUPPORTED_APPS.map((entry) => entry.repo);
    expect(iids).toEqual([...new Set(iids)]);
    expect(slugs).toEqual([...new Set(slugs)]);
    expect(repos).toEqual([...new Set(repos)]);
    for (const entry of SUPPORTED_APPS) {
      expect(entry.slug).toBe(supportedAppSlug(entry.title));
      expect(entry.repo.startsWith('https://')).toBe(true);
      expect(entry.issueUrl.endsWith(`/${entry.iid}`)).toBe(true);
    }
  });
});

test.describe('inspect ports contract', () => {
  test('ports is the routed, unrouted and refused object', () => {
    const parsed = inspectPortsSchema.parse({
      primary: 80,
      source: 'compose',
      routed: [80],
      unrouted: [
        {
          port: 3000,
          reason: 'secondary',
          routable: true,
          hint: 'proxy_rule_create',
        },
      ],
      refused: [{ port: 3306, reason: 'datastore', routable: false, service: 'db' }],
      compose: [80, 3000],
      dockerfile_expose: null,
    });
    expect(parsed.routed).toEqual([80]);
    expect(parsed.unrouted[0]?.routable).toBe(true);
    expect(parsed.refused[0]?.routable).toBe(false);
    expect(inspectPortsSchema.safeParse([80]).success).toBe(false);
  });
});

test.describe('usage statistics payloads', () => {
  test('an empty PHP map arrives as an array and a filled one as an object', () => {
    expect(bandwidthSeriesSchema.parse([])).toEqual([]);
    expect(bandwidthSeriesSchema.parse({ '2026-09-01': 12 })).toEqual({ '2026-09-01': 12 });
    expect(bandwidthSeriesSchema.safeParse([12]).success).toBe(false);
    const overview = visitorOverviewSchema.parse({
      unique: 0,
      total: 0,
      visits: { records: [], total: 0 },
      visits_length: [],
    });
    expect(overview.unique).toBe(0);
  });
});
