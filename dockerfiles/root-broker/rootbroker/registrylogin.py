"""The project's registry login for one pull, read from the config directory the engine writes into the account's home.

Only `<home>/.panelalpha-registry-<16 hex>/config.json`, opened without links and as a regular file, and only the
entry for the image's own registry goes to the daemon, as the X-Registry-Auth header `docker pull` sends.
"""

import base64
import json
import os
import re
import stat

from . import paths
from .refused import Refused

DIRECTORY = re.compile(r'\.panelalpha-registry-[0-9a-f]{16}')
CONFIG = 'config.json'
LIMIT = 1 << 16
DOCKER_HUB = 'docker.io'
DOCKER_HUB_KEY = 'https://index.docker.io/v1/'


def directory(account, value):
    """The account's own login directory, refused when the value names anything else."""
    if os.path.dirname(value) != account.home.rstrip('/') or not DIRECTORY.fullmatch(os.path.basename(value)):
        raise Refused(f"'{value}' is not a registry login directory in the home of {account.name}")
    return value


def registry_of(image):
    """The registry host of an image reference, `docker.io` when the first part names none."""
    first, slash, _ = image.partition('/')
    if slash and ('.' in first or ':' in first or first == 'localhost'):
        return first.lower()
    return DOCKER_HUB


def login_file(account, path):
    """config.json in `path` below the homes directory, each component walked on its own with no link on the way."""
    home = account.home.rstrip('/')
    homes = os.path.dirname(home)
    with paths.Walk(os.open(homes, paths.DIRECTORY), homes) as walk:
        return walk.down([os.path.basename(home), os.path.basename(path)]).into(CONFIG, paths.READ).taken()


def config(account, path):
    """The decoded config.json below `path`; None when it is not there, not a plain file, or not JSON."""
    try:
        fd = login_file(account, path)
    except OSError:
        return None
    with os.fdopen(fd, 'rb') as f:
        if not stat.S_ISREG(os.fstat(f.fileno()).st_mode):
            return None
        data = f.read(LIMIT + 1)
    if len(data) > LIMIT:
        return None
    try:
        decoded = json.loads(data)
    except (ValueError, RecursionError):
        return None
    return decoded if isinstance(decoded, dict) else None


def header(account, path, image):
    """X-Registry-Auth for `image`, or None when the login holds nothing for its registry."""
    auths = (config(account, path) or {}).get('auths')
    host = registry_of(image)
    entry = auths.get(DOCKER_HUB_KEY if host == DOCKER_HUB else host) if isinstance(auths, dict) else None
    encoded = entry.get('auth') if isinstance(entry, dict) else None
    if not isinstance(encoded, str):
        return None
    try:
        username, _, password = base64.b64decode(encoded, validate=True).decode().partition(':')
    except (ValueError, UnicodeDecodeError):
        return None
    login = {'username': username, 'password': password, 'serveraddress': DOCKER_HUB_KEY if host == DOCKER_HUB else host}
    return base64.urlsafe_b64encode(json.dumps(login).encode()).decode()
