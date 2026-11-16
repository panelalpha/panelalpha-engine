"""Whether a file root acts on could have been written by someone it does not trust: the rule pa-root-owned applies
for the broker's entrypoint and services, here for its actions."""

import shlex
import stat


def written_only_by(status, owners):
    """Owned by one of `owners`, and writable by no group and no one else, whichever group it has."""
    return status.st_uid in owners and not status.st_mode & (stat.S_IWGRP | stat.S_IWOTH)


def fix(status, owners, path):
    """The one command that leaves `path` as written_only_by asks; root is one of every caller's owners."""
    quoted = shlex.quote(path)
    if status.st_uid in owners:
        return f'chmod go-w {quoted}'
    return f'chown root:root {quoted} && chmod go-w {quoted}'
