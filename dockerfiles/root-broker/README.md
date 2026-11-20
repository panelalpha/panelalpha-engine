# Root broker

The engine's core runs as `www-data` with no privileges and no docker client. Everything it must do as root is one of
a fixed list of named actions, asked of the root broker: a small Python (stdlib only) process in the `root-broker`
container. It checks every value of a request, refuses a path that leads through a link, and runs only its own
command or API call for that action, never one it is handed.

[ACTIONS.md](ACTIONS.md) lists every action, with the core method that asks for it and what it runs.

## Where it runs

| | |
|---|---|
| Container | `root-broker` in the engine's compose project, image `dockerfiles/Dockerfile-root-broker` (a runtime only: this directory is mounted read-only at `/usr/local/lib/panelalpha`, in core too) |
| Process | `pa-root-broker` (`rootbroker.server`), supervised by s6 with the `tenant-network` and `logrotate` services (`config/root-broker/services/`) |
| Socket | `/run/panelalpha/root.sock`, `0660 root:www-data`, shared with core |
| Sees | `pid: host`, the host's `/var/run/docker.sock`, `/home`, `/opt/panelalpha`, `/etc/letsencrypt`, the backup stage |
| Client | `pa-root` in the core container (`rootbroker.client`); `pa-broker` in its own (`rootbroker.direct`) |

The server forks one process per request. A request for the host's namespaces (`--host`) enters pid 1's namespaces
and root first (`hostns`), as `nsenter --target 1 --all` does.

## Who may ask

A request is refused unless its peer, read from `SO_PEERCRED`, is uid 0 or `www-data` **and** runs in the mount
namespace of the core container's first process (`peer.py`): a host process with the same uid could otherwise reach
the socket through `/proc/<pid>/root`. The core container is found on the host's daemon as the running `core` service
of the broker's own compose project.

## Root on the host

```
pae-broker [--host] [--pty] [--env NAME]... AREA.ACTION -- VALUE...
```

`pae-broker` is `scripts/pae-broker.sh`, which `scripts/pae-command.sh register` links into `/usr/local/bin` beside
`pae`. It runs `docker compose exec -T root-broker pa-broker` with the same arguments, with a terminal only for
`--pty` on one, and passes each `--env NAME` as `-e NAME`, so a value never appears on a command line.

In the container, `pa-broker` runs any action for root on the host, who reaches it through `docker exec`; the
installers call it that way. It takes `pa-root`'s arguments and opens no socket: it forks the broker's own request process
(`Direct`, a `Broker` that admits uid 0 in place of core) and talks to it over a socket pair. The same action table
checks the values, a refusal says the same as `pa-broker: refused: …` with the same exit code, and the audit line is a
socket request's with `"via": "pa-broker"`, written to the container's own stdout, so it is in `docker compose logs
root-broker`. When Docker does not say which process the container runs, nothing runs (exit 69). Re-applying the tenant
firewall with every port:

```
pae-broker --host host.applyTenantNetworkFirewall --
```

## A request

```
pa-root [--host] [--pty] [--env NAME]... AREA.ACTION -- VALUE...
```

- `AREA.ACTION` names one entry of the action table; there is no other entry point.
- Values are strings, checked by the action, never interpreted by a shell.
- `--env NAME` passes one of the caller's variables, only for an action that lists it (`passes`); secrets travel this
  way, never as values.
- `--host` asks for the host's namespaces; an action allows it (`on_host`) or requires it (`host_only`).
- `--pty` is for the one action that runs a terminal (`compose.execWithTty`).

The client and the broker speak frames (`protocol.py`): one type byte, a 4-byte length, the payload. The client sends
`REQUEST` (JSON), its stdin as `STDIN` frames and `STDIN_END`; the broker answers with `STDOUT`, `STDERR` and one
`EXIT`. A client that hangs up stops its request: the command is killed, an API run is removed.

| Exit | Meaning |
|---|---|
| the command's | the action ran; its exit code is the caller's |
| 64 | the client was called wrongly |
| 69 | an account's container or daemon is not running |
| 76 | the frames were wrong |
| 77 | refused: a value, a path or the peer failed a check (`pa-root: refused: …` on stderr) |

Every request is one `pa-root-audit {...}` line on the broker's stdout: peer pid and uid, action, values (secrets as
`<secret>`), the variables passed, the exit code and the seconds it took, and `via` for one `pa-broker` ran. Never what
came on stdin.

```
docker compose logs root-broker | grep pa-root-audit
```

## How an action is declared

Each area is a module of `rootbroker/actions/` with an `ACTIONS` table; `actions.registry()` joins them under
`area.action`. An entry is one of:

- **`Action(params, build)`**: a tuple of `Param(check, mode, roots, …)`, one per value, and a builder returning the
  argv. A path value is opened a component at a time without following links (`paths.place`, openat2
  `RESOLVE_NO_SYMLINKS`, else `O_NOFOLLOW`) below one of the named roots (`actions.ROOTS`) and given to the command
  as `./name` or `/proc/self/fd/N`. `identity='account'` runs it as the account whose uid and gid lead the values,
  after the path walk is done as that account.
- **`Command(read, build)`**: `read` takes the values in order, declared as `reads(check, flag('name'),
  group(check, …), rest(check))` (`sequence.py`): a plain check from `values.py` takes one value (`one`), `flag` an
  optional word, `group` a counted list, `rest` every value left, `step(take)` one value its own way and `constant`
  none. Each gives a `Reads` of one checked value per step, and `reads` joins them; `build(context, *values)` returns
  the argv. Flags: `on_host`, `host_only`, `namespaces`, `passes`, `secret`, `detach`, `terminal`.
- **A native action** (`native=True`): the builder returns a callable the broker runs itself, writing to the
  client's stdout and stderr, with the caller's passed variables and its stdin at hand (`output.Sent`). On a daemon
  the builder is a `DaemonCall` (`daemoncall.py`) given by `hostapi.asking` or `accountdocker.asking`: the area says
  how its daemon is opened and gives the `FAILURES` table that turns what goes wrong into a line on stderr and an exit
  code (`failures.py`).

The value checks mirror `App\System\Privileged\Arguments` on the core side: the same rules, checked again.

## Docker

The broker reaches Docker through the Engine API, never a docker CLI, except for compose:

| Route | Area | How |
|---|---|---|
| An account's own daemon | `account-docker` | `accountdocker.asking`: the DinD socket below the account container's root, opened without links once the host's daemon names the container; every reply is the tenant's, read up to a size and type-checked |
| A command in an account's container | `account-container` | an exec through the host's daemon, once its inspect shows the account's own container; the command ends with the caller's connection |
| An account's compose and buildx | `account-compose` | the CLI as the account in a sandbox (`sandbox.py`) that sees only the home and a relay to the account's daemon |
| The host's own daemon | `docker`, `lines`, `build-cache`, `probes`, `host`, `scripts` | `hostapi.asking` on `/var/run/docker.sock` (`hostdaemon.py`), errors in the CLI's words |
| A command in an engine service | `compose` | an exec in the service's running container on the host's daemon, found by the broker's own compose project (`engineservices.py`) |
| `up`, `down`, `build`, `pull` of a project | `compose`, `lines` | `docker compose`, an Engine API client run by the broker; a project's file is checked (`composefiles.py`) and an account's rendered against the templates' shape (`composepolicy.py`) |

An action on the host's daemon prints what the docker CLI printed for the same command, so core parses its output
unchanged. `relay.py` splits Docker's 8-byte-headed stream frames, and relays a hijacked exec or attach stream both
ways, the client's stdin included.

## Adding an action

1. The core side: a typed method on the area's `App\System\Privileged\Root*` class that checks its values with
   `Arguments` and returns the `pa-root` request. Never a raw argv or a shell string.
2. The broker side: an entry in the area's `ACTIONS` with its values checked; a path through `Param` and a root.
3. A case in the area's `tests/*-cases.json` (the request, and for a command the argv it must run), mirrored by the
   PHP provider in `tests/unit/{Unit,Integration}/System/Privileged/*Cases.php`: the broker's tests plan the case, the PHP tests
   build the same request. A native action gets its own test module against a fake daemon (`test_host_daemon.py` is
   the pattern).
4. A docstring on a native action's work function: it is the action's line in ACTIONS.md. Then
   `cd tests && python3 actionsdoc.py > ../ACTIONS.md`.

## Tests

```
cd dockerfiles/root-broker/tests && python3 -m unittest discover -p 'test_*.py'
```

A test serves a fake daemon on a unix socket and the broker from a forked process (`support.Serving`), and calls it
through the real client. A few tests need root or a compose plugin and skip without them.

`local/pa-root-local` answers requests without the broker or root, for the engine's PHP tests and the dind-test
harness: it plans the request with the broker's own table and runs it as the caller, with `PA_ROOT_LOCAL_ROOTS`
moving the roots, and a native action against `DOCKER_HOST`'s daemon.

## Files

| Module | What it is |
|---|---|
| `server.py`, `runner.py`, `channel.py`, `output.py` | the socket, the request process, the client's connection, an action's output |
| `direct.py`, `pa-broker` | an action run by root in the broker's container, on a request process of its own |
| `protocol.py`, `client.py`, `terminal.py` | the frames, `pa-root`, the pty's size; the only modules core's client imports |
| `peer.py`, `hostns.py`, `audit.py`, `libc.py` | who may ask, the host's namespaces, the audit line, the C calls the os module lacks |
| `actions/` | the areas and their `ACTIONS`; `sequence.py` and `__init__.py` hold `Command`, `Action`, `Param`, `Policy` |
| `actions/daemoncall.py`, `actions/hostapi.py`, `actions/accountdocker.py`, `actions/failures.py` | `DaemonCall`, the native build on a daemon, the host's and an account's daemon and their `Failures` tables |
| `actions/params.py`, `actions/builders.py`, `actions/shell.py` | the `files` and `account` areas' values, the argv builders actions share (`script`: a shell script reading its values as `$1...`), shell words quoted as the engine's PHP quotes them |
| `actions/engineservices.py` | the engine's services: their containers (`Service`) and the fixed commands core runs in them (`EngineExec`) |
| `actions/hostruns.py`, `actions/hostbuilds.py` | a run on the host's daemon (`HostRun` of a `Spec`), a build (`Build` of a `ContextArchive`), a manifest read |
| `values.py`, `paths.py`, `realpaths.py`, `filestream.py`, `hostvalues.py` | value checks, the one linkless `Walk`, `realpath -e` below a root, the file stream |
| `accounts.py`, `innerprocesses.py`, `registrylogin.py`, `tarballs.py` | the host's accounts, an account's processes, a pull's registry login, a file out of an archive |
| `dockerapi.py`, `hostdaemon.py`, `accountdaemon.py`, `replies.py` | the Engine API client with its `Containers`, `Images`, `Volumes` and `Networks`, the host's and an account's daemon, reply checks |
| `relay.py`, `cli.py` | Docker's stream frames and their relay, what the docker CLI printed |
| `sandbox.py`, `composefiles.py`, `composepolicy.py` | the account's compose sandbox and the compose file checks |
| `dindrecovery.py`, `tenantports.py`, `projectstats.py`, `logrotation.py` | the stuck-DinD recovery, the tenant network's ports, project statistics, the web server's reopen after the `logrotate` service's rotation |
| `scriptrun.py` | an engine script a native action runs, handed what the action read of the host's Docker |
| `pa-root-owned`, `pa-file-stream` | the root-only check of a host script, the linkless file stream |
