import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';
import { systemEgressListResponseSchema, systemEgressUnblockResponseSchema } from '@/schemas';

const SETTINGS = [
  'EGRESS_BLOCK_SMTP',
  'EGRESS_NEW_CONNECTIONS_PER_SECOND',
  'EGRESS_NEW_DESTINATIONS_PER_MINUTE',
  'EGRESS_NEW_DESTINATIONS_BURST',
  'EGRESS_WEB_NEW_DESTINATIONS_PER_MINUTE',
  'EGRESS_WEB_NEW_DESTINATIONS_BURST',
  'EGRESS_SCAN_BLOCK_MINUTES',
];

test.describe('System outbound limits', () => {
  test('lists whether the limits are in place, their settings and the events in force', async ({
    api,
  }) => {
    const listed = await api.listEgressRaw();
    await expectStatus(listed, 200);
    const body = systemEgressListResponseSchema.parse(listed.body);

    expect(body.active, 'the limits are in place on an installed engine').toBe(true);
    expect(Object.keys(body.settings).sort()).toEqual([...SETTINGS].sort());
    for (const event of body.data) {
      expect(event.active, `${event.owner} ${event.kind} is listed as in force`).toBe(true);
      expect(event.lifted_at).toBeNull();
    }
  });

  test('lists every event of the last days, and refuses a day count outside 1 to 90', async ({
    api,
  }) => {
    const week = await api.listEgressRaw(7);
    await expectStatus(week, 200);
    systemEgressListResponseSchema.parse(week.body);

    for (const days of [0, 91, 'week']) {
      await expectStatus(await api.listEgressRaw(days), 422);
    }
  });

  test('lifting the block of a name with none lifts nothing', async ({ api }) => {
    const lifted = await api.unblockEgressRaw('no-such-project-egress');
    await expectStatus(lifted, 200);
    expect(systemEgressUnblockResponseSchema.parse(lifted.body)).toEqual({
      lifted: false,
      addresses: [],
    });
  });

  test('refuses an owner that is not a project or build name', async ({ api }) => {
    for (const owner of ['-starts-with-dash', 'has space', 'a'.repeat(129)]) {
      await expectStatus(await api.unblockEgressRaw(owner), 422);
    }
  });
});
