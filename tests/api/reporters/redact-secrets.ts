import type { Reporter } from '@playwright/test/reporter';

import { redactArchivesIn, usableSecrets } from '../helpers/redact-archives';
import {
  clearRegisteredSecretFiles,
  readRegisteredSecretFiles,
  registeredSecrets,
} from '../helpers/secret-registry';

interface Options {
  /** Directories whose archives are rewritten: the test output and the HTML report. */
  dirs: string[];
}

/** Environment variables whose values must never reach a trace or the report. */
const SECRET_ENV = ['API_TOKEN', 'LICENSE_KEY', 'SYSTEM_UPDATE_LICENSE_KEY'];

/**
 * Replaces the API token, other secrets from the environment and the passwords the
 * tests registered in every retained trace, in the HTML report's copies of them and in
 * the report's embedded data. Listed after the
 * `html` reporter, whose onEnd writes those copies; reporters end in order.
 */
export default class RedactSecretsReporter implements Reporter {
  constructor(private readonly options: Options) {}

  printsToStdio(): boolean {
    return false;
  }

  async onEnd(): Promise<void> {
    const secrets = usableSecrets([
      ...SECRET_ENV.map((name) => process.env[name]),
      ...registeredSecrets(),
      ...readRegisteredSecretFiles(),
    ]);
    let changed = 0;
    for (const dir of this.options.dirs) {
      changed += await redactArchivesIn(dir, secrets);
    }
    if (changed > 0) {
      console.log(`  Redacted secrets from ${changed} archive(s) and file(s).`);
    }
    clearRegisteredSecretFiles();
  }
}
