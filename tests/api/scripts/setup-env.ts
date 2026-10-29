import path from 'node:path';

import { testNetwork } from '@/helpers/test-network';
import {
  ENGINE_API_PORT,
  defaultOptions,
  maskToken,
  suiteRoot,
  writeEngineEnv,
  type EngineEnvOptions,
} from './lib/engine-env';

/** Command line for creating an `env/.env`. `bun run test` calls the same code path. */

const USAGE = `
Point the API test suite at an engine.

  bun run env:setup                                  interactive
  bun run env:setup -- --host ${testNetwork.public(10)}           mint a token over SSH
  bun run env:setup -- --host engine.example.com --token '3|abc…'

Options
  --host <host>        Engine host: an IP address or a hostname. Optional on
                       the engine itself (hostname / public IPv4 is used).
  --token <token>      API token. Minted over SSH when omitted.
  --profile <name>     Write env/.env.<name> instead of env/.env, for a second
                       engine. Run it with TEST_ENV=<name>.
  --port <port>        Engine API port (default ${ENGINE_API_PORT}).
  --ssh-user <user>    SSH user for minting the token (default root).
  --ssh-key <path>     SSH private key for minting the token.
  --token-name <name>  Name recorded for the minted token (default api-tests).
  --force              Overwrite an existing env file.
  --no-verify          Skip the connection check before writing.
  --help               Show this.

The base domain for generated extra names is not stored: bun run test reads the
engine's panelalpha.direct (or cert_domain) zone at run time. Set DOMAIN in
the file by hand only to override that.
`.trim();

function parseArgs(argv: string[]): EngineEnvOptions {
  if (argv.includes('--help') || argv.includes('-h')) {
    console.log(USAGE);
    process.exit(0);
  }

  const options = defaultOptions();

  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    /** Reads the value after a flag, failing loudly when it is missing. */
    const value = () => {
      const next = argv[++i];
      if (next === undefined || next.startsWith('--')) {
        throw new Error(`${arg} needs a value.`);
      }
      return next;
    };

    switch (arg) {
      case '--host':
        options.host = value();
        break;
      case '--token':
        options.token = value();
        break;
      case '--profile':
        options.profile = value();
        break;
      case '--port':
        options.port = Number.parseInt(value(), 10);
        break;
      case '--ssh-user':
        options.sshUser = value();
        break;
      case '--ssh-key':
        options.sshKey = value();
        break;
      case '--token-name':
        options.tokenName = value();
        break;
      case '--force':
        options.force = true;
        break;
      case '--no-verify':
        options.verify = false;
        break;
      default:
        throw new Error(`Unknown option ${arg}. Run with --help.`);
    }
  }

  return options;
}

async function main(): Promise<void> {
  const options = parseArgs(process.argv.slice(2));
  const result = await writeEngineEnv(options);

  console.log(`\nWrote ${path.relative(suiteRoot, result.file)}`);
  console.log(`  API_BASE_URL  ${result.apiBaseUrl}`);
  console.log(`  API_TOKEN     ${maskToken(result.token)}`);
  console.log(
    options.profile
      ? `\nRun it with:\n  TEST_ENV=${options.profile} bun run test`
      : '\nRun it with:\n  bun run test'
  );
}

await main().catch((error: unknown) => {
  console.error(`\n${error instanceof Error ? error.message : String(error)}`);
  process.exit(1);
});
