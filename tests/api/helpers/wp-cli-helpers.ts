import { expect } from '@playwright/test';
import type { EngineApi } from '@/clients/engine-api';
import { waitForCondition } from './retry';
import { redactAndTruncate } from './redact';

export interface WpCliCommandResult {
  exit_code: number;
  stderr: string;
  stdout?: string;
}

export function isKnownWordPressChecksumNoise(stderr: string): boolean {
  const trimmed = stderr.trim();
  if (!trimmed) {
    return false;
  }

  const lines = trimmed
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter(Boolean);
  if (lines.length === 0) {
    return false;
  }

  return lines.every(
    (line) =>
      line.includes('wp-includes/php-ai-client/') ||
      /^Warning: File (doesn't exist|should not exist):/i.test(line) ||
      /^Error: WordPress installation doesn't verify against checksums\./i.test(line)
  );
}

export function assertWordPressCoreChecksums(
  response: WpCliCommandResult,
  context = 'Core integrity check'
): void {
  if (response.exit_code === 0) {
    return;
  }

  if (response.exit_code === 1 && isKnownWordPressChecksumNoise(response.stderr)) {
    return;
  }

  expect(response.exit_code, `${context} failed. stderr: ${response.stderr}`).toBe(0);
}

export async function waitForWpCliReady(
  api: EngineApi,
  username: string,
  wpPath: string,
  options: { timeout?: number; interval?: number } = {}
): Promise<void> {
  const wpPathArg = `--path=${wpPath}`;
  let last = 'no response yet';

  await waitForCondition(
    async () => {
      try {
        const response = await api.executeWpCliCommand(username, [
          'core',
          'is-installed',
          wpPathArg,
        ]);
        last = `exit ${response.exit_code}, stderr: ${redactAndTruncate(response.stderr, 300)}`;
        return response.exit_code === 0;
      } catch (error) {
        last = `request failed: ${redactAndTruncate(error instanceof Error ? error.message : String(error), 300)}`;
        return false;
      }
    },
    {
      describeLast: () => last,
      timeout: options.timeout ?? 120_000,
      interval: options.interval ?? 2_000,
      message: `WP-CLI not ready for ${username} (${wpPath})`,
    }
  );
}
