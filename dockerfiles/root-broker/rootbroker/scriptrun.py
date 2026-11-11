"""An engine script a native action runs itself, handed what the action read of the host's Docker on its stdin or in
its environment, so the script runs no docker command for it."""

import subprocess

from . import runner


def ran(argv, stdin=b'', environment=None):
    """The script's run with the broker's fixed environment and `environment`, its output kept."""
    return subprocess.run(argv, input=stdin, capture_output=True, env={**runner.ENVIRONMENT, **(environment or {})})


def relayed(output, done):
    """The run's output on the action's, and its exit code."""
    output.stdout(done.stdout)
    output.stderr(done.stderr)
    return done.returncode
