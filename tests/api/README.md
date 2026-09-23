# Engine tests

These tests talk to a real PanelAlpha Engine. They create accounts, sites and projects, check that they work, and remove them again. Run them only on an engine you are allowed to change.

You need Node 20 or newer.

## Run

From this folder:

```bash
npm install
npm test
```

The first run needs the engine's address and a token. On the engine itself that is filled in for you. From your own computer it asks which engine to use, connects over SSH, and saves the details in `env/.env`. Later runs go straight to the tests. Do not commit that file.

To choose the engine yourself:

```bash
npm run env:setup -- --host engine.example.com
```

Add `--token` if you already have one. `--help` lists the rest.

`npm test` is the everyday run. It checks the engine's API, then deploys each application from the list and waits until it is online. That takes hours.

Anything you put after `--` is passed through. `npm test -- tests/users` runs one folder. `npm test -- --grep "suspend"` runs tests whose name matches.

Some tests skip when the engine does not have what they need, for example no firewall, or no way to run commands on the server. The run prints each skip and why. A run that skipped half the tests is not a full pass.

## Groups

| Command | What it does |
| --- | --- |
| `npm test` | The everyday run: logic checks, the API, and the application deploys |
| `npm run test:smoke` | A short check that the engine answers |
| `npm run test:unit` | Logic only. No engine, so it works offline |
| `npm run test:supported-apps` | Only the application deploys. These also run inside `npm test` |
| `npm run test:slow` | Turns the firewall off and on, and tries every PHP version |
| `npm run test:cli` | The engine's own commands. Only works when the tests run on the engine's server |
| `npm run test:webserver-change` | Switches the web server, then switches it back. This changes the engine for everyone on it |
| `npm run test:update` | The engine's update |
| `npm run test:ui` | Watch the tests in a window |
| `npm run report` | Open the report from the last run |
| `npm run check` | Check the test code before you commit it |

`npm run test:supported-apps` deploys each application from a fixed list, waits until it is online, and opens its address. The same run also checks, once each: a refused create, an archive with no file, a working copy pushed back to the live site, a backup, stopping and starting the containers, a site that lost its front page, a git hook, and WordPress on an ordinary account. `SUPPORTED_APPS=koel,ntfy` runs only those applications from the list. The once-each checks still run.

`npm run test:slow`, `npm run test:cli`, `npm run test:webserver-change` and `npm run test:update` stay out of `npm test` because they change the whole engine, or they only make sense on the engine's own server.

## When something fails

```bash
npm run report
```

This serves the report on port 9323 and prints the address to open. If you are not on that machine, either allow the port or tunnel it:

```bash
ssh -L 9323:127.0.0.1:9323 root@engine.example.com
```

A failed test keeps a trace. Open it from the report.
