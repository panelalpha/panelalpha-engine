import { expect, test } from '@/fixtures/test-options';
import { getDomainBasePath } from '@/helpers/file-path-helpers';
import { uniqueId } from '@/helpers/random';
import { readPage, waitForPage } from '@/helpers/site-page';

interface DbAnswer {
  ok: boolean;
  rows?: string[];
  error?: string;
}

function probePage(host: string, database: string, user: string, password: string, row: string) {
  const quote = (value: string) => `'${value.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  return `<?php
header('Content-Type: application/json');
try {
    $pdo = new PDO('mysql:host=' . ${quote(host)} . ';dbname=' . ${quote(database)}, ${quote(user)}, ${quote(password)}, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE IF NOT EXISTS pa_probe (v VARCHAR(64) NOT NULL)');
    $pdo->prepare('INSERT INTO pa_probe (v) VALUES (?)')->execute([${quote(row)}]);
    echo json_encode(['ok' => true, 'rows' => $pdo->query('SELECT v FROM pa_probe ORDER BY v')->fetchAll(PDO::FETCH_COLUMN)]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
`;
}

function parsed(body: string): DbAnswer {
  try {
    return JSON.parse(body) as DbAnswer;
  } catch {
    return { ok: false, error: body.slice(0, 200) };
  }
}

/** A PHP page on the site uses the database and user the API made, then loses the old password. */
test.describe('a project uses its own database', () => {
  test('the project connects with its credentials, and a new password replaces the old', async ({
    api,
    anonymousRequest,
    settings,
    userFactory,
  }) => {
    test.setTimeout(240_000);
    const user = await userFactory.createUser();
    const pagePath = `${getDomainBasePath(user.domain)}/pa-db-probe.php`;
    const url = `https://${user.domain}/pa-db-probe.php`;
    const write = (password: string, row: string) =>
      api.putFileContents(
        user.username,
        pagePath,
        probePage(settings.mysql.host, user.database, user.mysqlUsername, password, row)
      );

    await test.step('the created user writes and reads a row', async () => {
      await write(user.mysqlPassword, 'first');
      const page = await waitForPage(
        anonymousRequest,
        url,
        (answer) => parsed(answer.body).ok,
        'the project never reached its database'
      );
      expect(parsed(page.body).rows).toEqual(['first']);
    });

    const next = uniqueId('Pw9-');
    await test.step('change the password over the API', async () => {
      await api.changeMySqlUserPassword(user.username, user.mysqlUsername, next);
    });

    await test.step('the old password is refused', async () => {
      await write(user.mysqlPassword, 'old');
      const page = await readPage(anonymousRequest, url);
      const answer = parsed(page.body);
      expect(answer.ok, 'the old password still logs in').toBe(false);
      expect(answer.error).toMatch(/Access denied/i);
    });

    await test.step('the new password reaches the same data', async () => {
      await write(next, 'second');
      const page = await readPage(anonymousRequest, url);
      const answer = parsed(page.body);
      expect(answer.ok, answer.error).toBe(true);
      expect(answer.rows).toEqual(['first', 'second']);
    });
  });
});
