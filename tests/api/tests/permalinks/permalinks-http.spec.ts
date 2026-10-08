import { expect, test } from '@/fixtures/test-options';
import { CleanupSteps } from '@/helpers/cleanup-step';
import type { EngineApi } from '@/clients/engine-api';
import {
  PERMALINK_STRUCTURES,
  applyPermalinkStructure,
  assertPermalinkStructureSet,
  assertPrettyPermalink,
  fetchWithRetry,
  generateUniqueSlug,
  getPermalinkUrl,
  parseWpCliId,
  slugPattern,
} from '@/helpers/permalinks-http-helpers';
import { wpCliUnavailableReason, wpPath } from '@/helpers/wpcli-helpers';
import { expectStatus } from '@/helpers/expect-status';

/**
 * Setting a permalink structure has to change the URL WordPress hands out *and*
 * the URL the webserver will actually serve — the two come apart when the
 * rewrite rules do not reach nginx, Apache or LiteSpeed.
 *
 * Every case here publishes a real post, asks WordPress for its permalink, and
 * fetches it over HTTPS.
 */

interface PermalinkCase {
  structure: string;
  /** Builds the path the permalink is expected to have. */
  expectedPath: (context: { postSlug: string; categorySlug: string }) => RegExp;
  /** Set when the structure needs a category to render its URL. */
  needsCategory?: boolean;
}

const CASES: Record<string, PermalinkCase> = {
  'post name': {
    structure: PERMALINK_STRUCTURES.postName,
    expectedPath: ({ postSlug }) => new RegExp(`^/${slugPattern(postSlug)}/?$`),
  },
  'day and name': {
    structure: PERMALINK_STRUCTURES.dayAndName,
    expectedPath: ({ postSlug }) =>
      new RegExp(`^/\\d{4}/\\d{2}/\\d{2}/${slugPattern(postSlug)}/?$`),
  },
  'month and name': {
    structure: PERMALINK_STRUCTURES.monthAndName,
    expectedPath: ({ postSlug }) => new RegExp(`^/\\d{4}/\\d{2}/${slugPattern(postSlug)}/?$`),
  },
  numeric: {
    structure: PERMALINK_STRUCTURES.numeric,
    expectedPath: () => /^\/archives\/\d+\/?$/,
  },
  custom: {
    structure: PERMALINK_STRUCTURES.custom,
    needsCategory: true,
    expectedPath: ({ categorySlug, postSlug }) =>
      new RegExp(`^/${slugPattern(categorySlug)}/${slugPattern(postSlug)}/?$`),
  },
};

test.describe('permalinks are served over HTTP', () => {
  let originalStructure: string;

  test.beforeEach(async ({ api, setupUser }) => {
    const reason = await wpCliUnavailableReason(api, setupUser);
    test.skip(Boolean(reason), reason ?? '');

    originalStructure = (
      await api.executeWpCliCommand(setupUser.username, [
        'option',
        'get',
        'permalink_structure',
        wpPath(setupUser),
      ])
    ).stdout.trim();
  });

  test.afterEach(async ({ api, setupUser }) => {
    // beforeEach can skip before it reads the structure; there is nothing to restore then.
    if (originalStructure === undefined) {
      return;
    }

    const cleanup = new CleanupSteps();
    await cleanup.run(
      'restore the permalink structure',
      () => applyPermalinkStructure(api, setupUser.username, originalStructure, wpPath(setupUser)),
      {
        verify: async () =>
          (
            await api.executeWpCliCommand(setupUser.username, [
              'option',
              'get',
              'permalink_structure',
              wpPath(setupUser),
            ])
          ).stdout.trim() === originalStructure,
        describe: () => `the permalink structure is not back to "${originalStructure}"`,
      }
    );
    await cleanup.finish();
  });

  for (const [label, permalinkCase] of Object.entries(CASES)) {
    test(`a post is reachable under the ${label} structure`, async ({
      api,
      anonymousRequest,
      setupUser,
    }) => {
      const path = wpPath(setupUser);
      const run = (...args: string[]) =>
        api.executeWpCliCommand(setupUser.username, [...args, path]);

      const postSlug = generateUniqueSlug(`${label.replace(/\s+/g, '-')}-test`);
      const categorySlug = generateUniqueSlug('test-category');
      const postTitle = `${label} permalink test`;

      await applyPermalinkStructure(api, setupUser.username, permalinkCase.structure, path);
      await assertPermalinkStructureSet(api, setupUser.username, permalinkCase.structure, path);

      if (permalinkCase.structure === PERMALINK_STRUCTURES.postName) {
        await test.step('rewrite rules are generated for a pretty structure', async () => {
          const listed = await run('rewrite', 'list', '--format=json');

          expect.soft(listed.exit_code, listed.stderr).toBe(0);
          if (listed.exit_code === 0) {
            const rules = JSON.parse(listed.stdout) as unknown[];
            expect.soft(Array.isArray(rules)).toBe(true);
            expect
              .soft(rules.length, 'a pretty permalink structure produced no rewrite rules')
              .toBeGreaterThan(0);
          }
        });
      }

      const categoryId = permalinkCase.needsCategory
        ? await ensureCategory(run, categorySlug)
        : undefined;

      const created = await run(
        'post',
        'create',
        '--post_type=post',
        `--post_title=${postTitle}`,
        `--post_name=${postSlug}`,
        ...(categoryId ? [`--post_category=${categoryId}`] : []),
        '--post_status=publish',
        '--porcelain'
      );
      const postId = parseWpCliId(created.stdout);
      expect(postId, `WP-CLI returned no post id: ${created.stdout}`).toMatch(/^\d+$/);

      const cleanup = new CleanupSteps();
      try {
        const permalink = await getPermalinkUrl(
          api,
          setupUser.username,
          postId!,
          path,
          setupUser.domain
        );

        assertPrettyPermalink(permalink, permalinkCase.expectedPath({ postSlug, categorySlug }));

        const response = await fetchWithRetry(anonymousRequest, permalink, api);
        await expectStatus(response, 200, `${permalink} did not serve`);
        expect(await response.text()).toContain(postTitle);
      } catch (error) {
        cleanup.bodyFailed(error);
        throw error;
      } finally {
        await cleanup.run('delete the post the test created', () =>
          run('post', 'delete', postId!, '--force')
        );
        if (categoryId) {
          await cleanup.run('delete the category the test created', () =>
            run('term', 'delete', 'category', categoryId)
          );
        }
        await cleanup.finish();
      }
    });
  }

  /**
   * The plain structure has no rewrite rules at all — the post is reached by
   * query string, which is the fallback every other structure must improve on.
   */
  test('a post is reachable under the plain structure', async ({
    api,
    anonymousRequest,
    setupUser,
  }) => {
    const path = wpPath(setupUser);
    const run = (...args: string[]) => api.executeWpCliCommand(setupUser.username, [...args, path]);
    const postTitle = 'plain permalink test';

    await applyPermalinkStructure(api, setupUser.username, PERMALINK_STRUCTURES.plain, path);
    await assertPermalinkStructureSet(api, setupUser.username, PERMALINK_STRUCTURES.plain, path);

    const created = await run(
      'post',
      'create',
      '--post_type=post',
      `--post_title=${postTitle}`,
      '--post_status=publish',
      '--porcelain'
    );
    const postId = parseWpCliId(created.stdout);
    expect(postId, `WP-CLI returned no post id: ${created.stdout}`).toMatch(/^\d+$/);

    const cleanup = new CleanupSteps();
    try {
      const url = `https://${setupUser.domain}/?p=${postId!}`;
      const response = await fetchWithRetry(anonymousRequest, url, api);

      await expectStatus(response, 200, `${url} did not serve`);
      expect(await response.text()).toContain(postTitle);
    } catch (error) {
      cleanup.bodyFailed(error);
      throw error;
    } finally {
      await cleanup.run('delete the post the test created', () =>
        run('post', 'delete', postId!, '--force')
      );
      await cleanup.finish();
    }
  });
});

/** Returns the id of the category with `slug`, creating it when it does not exist. */
async function ensureCategory(
  run: (...args: string[]) => ReturnType<EngineApi['executeWpCliCommand']>,
  slug: string
): Promise<string> {
  const created = await run(
    'term',
    'create',
    'category',
    'Test Category',
    `--slug=${slug}`,
    '--porcelain'
  );
  const id =
    parseWpCliId(created.stdout) ??
    parseWpCliId((await run('term', 'get', 'category', slug, '--field=term_id')).stdout);

  expect(id, `could not create or find the category "${slug}"`).toMatch(/^\d+$/);
  return id!;
}
