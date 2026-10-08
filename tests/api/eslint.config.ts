import eslint from '@eslint/js';
import tseslint from 'typescript-eslint';
import prettierConfig from 'eslint-config-prettier';
import { noBareStatusAssertion } from './eslint-rules/no-bare-status-assertion';

export default tseslint.config(
  eslint.configs.recommended,
  ...tseslint.configs.recommendedTypeChecked,
  ...tseslint.configs.stylisticTypeChecked,
  prettierConfig,
  {
    languageOptions: {
      parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname },
    },
  },
  {
    files: ['**/*.ts'],
    rules: {
      '@typescript-eslint/no-unused-vars': [
        'error',
        { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrorsIgnorePattern: '^_' },
      ],
      '@typescript-eslint/consistent-type-imports': [
        'error',
        { prefer: 'type-imports', fixStyle: 'inline-type-imports' },
      ],
      '@typescript-eslint/no-floating-promises': 'error',
      '@typescript-eslint/await-thenable': 'error',
      '@typescript-eslint/no-misused-promises': 'error',
      '@typescript-eslint/prefer-optional-chain': 'error',
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-non-null-assertion': 'warn',
      'no-debugger': 'error',
      'prefer-const': 'error',
      'no-var': 'error',
      eqeqeq: ['error', 'always', { null: 'ignore' }],
      curly: ['error', 'all'],
    },
  },
  {
    files: ['tests/**/*.ts'],
    rules: {
      // Specs must go through the fixture layer so shared state stays injected.
      'no-restricted-imports': [
        'error',
        {
          paths: [
            {
              name: '@playwright/test',
              message:
                "Import { test, expect } from '@/fixtures/test-options' instead — it carries the Engine fixtures.",
            },
          ],
        },
      ],
      // A hard sleep hides a missing wait condition; use waitForCondition / expect.poll.
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "CallExpression[callee.object.name='page'][callee.property.name='waitForTimeout']",
          message: 'Hard waits are forbidden — poll for the condition you actually need.',
        },
      ],
      '@typescript-eslint/no-non-null-assertion': 'off',
    },
  },
  {
    files: ['tests/**/*.ts'],
    ignores: ['tests/unit/**'],
    plugins: { local: { rules: { 'no-bare-status-assertion': noBareStatusAssertion } } },
    rules: {
      // Bare status assertions hide the request and body; use expectStatus.
      'local/no-bare-status-assertion': 'error',
    },
  },
  {
    files: ['fixtures/**/*.ts'],
    rules: {
      // `async ({}, use) => …` is the Playwright idiom for a fixture with no dependencies.
      'no-empty-pattern': 'off',
    },
  },
  {
    files: ['clients/**/*.ts'],
    rules: {
      // APIResponse.json() is untyped; the clients assert status before parsing.
      '@typescript-eslint/no-unsafe-return': 'off',
      '@typescript-eslint/no-unsafe-assignment': 'off',
      '@typescript-eslint/no-unsafe-member-access': 'off',
      // EngineApi merges a class with an interface to expose the composed clients.
      '@typescript-eslint/no-unsafe-declaration-merging': 'off',
    },
  },
  {
    ignores: ['node_modules/**', '.playwright/**', 'playwright-report/**', 'test-results/**'],
  }
);
