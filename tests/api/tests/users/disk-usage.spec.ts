import { expect, test } from '@/fixtures/test-options';
import { expectStatus } from '@/helpers/expect-status';

/** The measured disk of a project, as `GET /projects/<u>` reports it. */
interface ProjectDisk {
  files_bytes: number | null;
  docker_bytes: number | null;
  total_bytes: number | null;
  limit_bytes: number | null;
  measured_at: string;
}

/**
 * A background job measures every project about every 15 minutes. A request never
 * measures, so a project that was not measured yet reads `disk: null`: both answers are valid.
 */
test.describe('project disk usage', () => {
  test('the project carries a disk block that is null or fully measured', async ({
    api,
    setupUser,
  }) => {
    const raw = await api.getUserRaw(setupUser.username);
    await expectStatus(raw, 200);
    const project = (raw.body as { data: { disk?: ProjectDisk | null } }).data;
    expect(project, 'the project resource has a disk key').toHaveProperty('disk');

    const disk = project.disk;
    if (disk === null || disk === undefined) {
      // Valid on a fresh engine; a skip here would count as unexpected in the run summary.
      test.info().annotations.push({ type: 'note', description: 'disk is null: not measured yet' });
      return;
    }

    expect(Object.keys(disk).sort(), 'the disk block has the documented fields').toEqual([
      'docker_bytes',
      'files_bytes',
      'limit_bytes',
      'measured_at',
      'total_bytes',
    ]);

    await test.step('the byte counts are non-negative integers', () => {
      for (const key of ['files_bytes', 'docker_bytes', 'total_bytes'] as const) {
        const value = disk[key];
        // A count is null when that part was not measured (a project without Docker data).
        if (value !== null) {
          expect(Number.isInteger(value), `${key} is an integer`).toBe(true);
          expect(value, key).toBeGreaterThanOrEqual(0);
        }
      }
    });

    await test.step('the total is files plus docker', () => {
      // ProjectDisk::latest: total_bytes is null only when both parts are null.
      const expected =
        disk.files_bytes === null && disk.docker_bytes === null
          ? null
          : (disk.files_bytes ?? 0) + (disk.docker_bytes ?? 0);
      expect(disk.total_bytes, 'total_bytes = files_bytes + docker_bytes').toBe(expected);
    });

    await test.step('the limit is null, -1 when unlimited, or a number of bytes', () => {
      if (disk.limit_bytes !== null && disk.limit_bytes !== -1) {
        expect(disk.limit_bytes, 'limit_bytes').toBeGreaterThanOrEqual(0);
      }
    });

    await test.step('the measurement time is an ISO 8601 UTC time', () => {
      expect(disk.measured_at, 'measured_at').toMatch(/^\d{4}-\d{2}-\d{2}T[\d:]+Z$/);
      expect(Number.isNaN(Date.parse(disk.measured_at)), 'measured_at parses').toBe(false);
    });
  });

  test('usage reports the docker bytes as null or a non-negative number', async ({
    api,
    setupUser,
  }) => {
    const usage = (await api.getUserUsage(setupUser.username)) as unknown as {
      docker: { usage: number | null; maximum: null };
    };

    expect(usage.docker, 'the usage payload has a docker entry').toBeDefined();
    expect(usage.docker.maximum, 'docker has no limit').toBeNull();
    // ProjectUsage: null before the first measurement or without Docker data.
    if (usage.docker.usage !== null) {
      expect(usage.docker.usage, 'docker usage in bytes').toBeGreaterThanOrEqual(0);
    }
  });
});
