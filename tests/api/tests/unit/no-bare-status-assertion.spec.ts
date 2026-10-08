import { Linter } from 'eslint';
import { expect, test } from '@/fixtures/test-options';
import { noBareStatusAssertion } from '../../eslint-rules/no-bare-status-assertion';

function reports(code: string): number {
  const linter = new Linter();
  const messages = linter.verify(
    code,
    [
      {
        files: ['**/*.ts'],
        plugins: { local: { rules: { 'no-bare-status-assertion': noBareStatusAssertion } } },
        rules: { 'local/no-bare-status-assertion': 'warn' },
        languageOptions: { ecmaVersion: 2023, sourceType: 'module' },
      },
    ],
    'sample.ts'
  );
  return messages.filter((m) => m.ruleId === 'local/no-bare-status-assertion').length;
}

test.describe('no-bare-status-assertion', () => {
  test('reports a numeric HTTP status', () => {
    expect(reports('expect(response.status()).toBe(200);')).toBe(1);
    expect(reports('expect(res.status).toEqual(404);')).toBe(1);
  });

  test('reports a constant or member expression as the expected value', () => {
    expect(reports('expect(response.status()).toBe(PROJECT_CREATE_ACCEPTED);')).toBe(1);
    expect(reports('expect(response.status()).toBe(codes.ok);')).toBe(1);
  });

  test('ignores a string or template literal expected value', () => {
    expect(reports("expect(task.status).toBe('completed');")).toBe(0);
    expect(reports("expect(created.data.status).toBe('active');")).toBe(0);
    expect(reports('expect(task.status).toBe(`done`);')).toBe(0);
  });

  test('ignores assertions on other properties', () => {
    expect(reports('expect(response.code).toBe(200);')).toBe(0);
  });
});
