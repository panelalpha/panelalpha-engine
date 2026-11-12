"""What a value must look like before the broker puts it into a command.

The same rules as App\\System\\Privileged\\Arguments, checked again.
"""

import re

from .refused import Refused

LINE_BREAK_OR_NUL = re.compile(r'[\x00\n\r]')
OCTAL_MODE = re.compile(r'[0-7]{3,4}')
SYMBOLIC_MODE = re.compile(r'[ugoa]*[-+=][rwxXst]*(?:,[ugoa]*[-+=][rwxXst]*)*')
OWNER_PART = r'(?:[a-z_][a-z0-9_-]{0,31}\$?|[0-9]+)'
OWNER = re.compile(OWNER_PART + r'(?::' + OWNER_PART + r')?')
INTEGER = re.compile(r'0|-?[1-9][0-9]*')
DECIMAL = re.compile(r'(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?')
MEMORY = re.compile(r'[1-9][0-9]*[bkmgBKMG]?')
FIND_OPERATORS = ('(', ')', '!', ',')
ACCOUNT_ID = re.compile(r'[0-9]+')
USERNAME = re.compile(r'[a-z_][a-z0-9_-]{0,31}\$?')
OWNER_IDS = re.compile(r'[0-9]+:[0-9]+')
URL = re.compile(r'https?://', re.IGNORECASE)
FOPEN_MODE = re.compile(r'[rwaxc][+bte]{0,3}')
HOSTNAME_PART = r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?'
HOSTNAME = re.compile(r'(?=.{1,253}\Z)(?:\*\.)?' + HOSTNAME_PART + r'(?:\.' + HOSTNAME_PART + r')*')
CERTIFICATE_SUBJECT = re.compile(r'/[^\x00-\x1f\x7f]*')
TAGS = ('F:', 'E:')
NAME = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]*')
IMAGE_DOMAIN = (r'(?:[a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9])(?:\.(?:[a-zA-Z0-9]|[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]))*'
                r'(?::[0-9]+)?')
IMAGE_PATH = r'[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*'
IMAGE_TAG = r'[\w][\w.-]{0,127}'
IMAGE_DIGEST = r'[A-Za-z][A-Za-z0-9]*(?:[-_+.][A-Za-z][A-Za-z0-9]*)*:[0-9a-fA-F]{32,}'
IMAGE_REFERENCE = re.compile(
    r'(?:' + IMAGE_DOMAIN + r'/)?' + IMAGE_PATH + r'(?:/' + IMAGE_PATH + r')*(?::' + IMAGE_TAG + r')?(?:@' + IMAGE_DIGEST + r')?',
    re.ASCII,
)
IMAGE_ID = re.compile(r'(?:sha256:)?[0-9a-f]{12,64}')
APACHE_MODULE = re.compile(r'[a-z0-9_]+')
ENVIRONMENT_NAME = re.compile(r'[A-Za-z_][A-Za-z0-9_]*')


def refuse(kind, value):
    raise Refused(f'{kind} {value!r} is not allowed')


def matching(pattern, kind):
    """A check that passes a value `pattern` matches whole and refuses any other as a `kind`."""
    def check(value):
        if not pattern.fullmatch(value):
            refuse(kind, value)
        return value
    return check


def one_of(allowed, kind):
    """A check that passes a value in `allowed` and refuses any other as a `kind`."""
    def check(value):
        if value not in allowed:
            refuse(kind, value)
        return value
    return check


def optional(check):
    return lambda value: value if value == '' else check(value)


name = matching(NAME, 'name')
username = matching(USERNAME, 'username')
apache_module = matching(APACHE_MODULE, 'Apache module')
environment_name = matching(ENVIRONMENT_NAME, 'environment variable name')
owner = matching(OWNER, 'owner')
integer = matching(INTEGER, 'number')
account_id = matching(ACCOUNT_ID, 'uid or gid')
flag = one_of(('0', '1'), 'flag')
octal_mode = matching(OCTAL_MODE, 'mode')
owner_ids = matching(OWNER_IDS, 'owner')
fopen_mode = matching(FOPEN_MODE, 'open mode')
hostname = matching(HOSTNAME, 'hostname')
certificate_subject = matching(CERTIFICATE_SUBJECT, 'certificate subject')


def path(value):
    if not value.startswith('/') or LINE_BREAK_OR_NUL.search(value):
        refuse('path', value)
    if '..' in value.split('/'):
        refuse('path', value)
    return value


def path_to_resolve(value):
    if not value.startswith('/') or LINE_BREAK_OR_NUL.search(value):
        refuse('path', value)
    return value


def mount_path(value):
    if ':' in path(value):
        refuse('mount path', value)
    return value


def image(value):
    if not IMAGE_REFERENCE.fullmatch(value) and not IMAGE_ID.fullmatch(value):
        refuse('image', value)
    return value


def mode(value):
    if not OCTAL_MODE.fullmatch(value) and not SYMBOLIC_MODE.fullmatch(value):
        refuse('mode', value)
    return value


def operand(value):
    if value.startswith('-') or LINE_BREAK_OR_NUL.search(value):
        refuse('argument', value)
    return value


def name_pattern(value):
    if value in FIND_OPERATORS:
        refuse('name pattern', value)
    return operand(value)


def archive_entry(value):
    """`.` or one name below tar's `-C`, so tar meets no link on the way to it."""
    if value != '.' and (value in ('', '..') or '/' in value):
        refuse('archive entry', value)
    return operand(value)


def archive_pattern(value):
    if value == '' or value.startswith('/') or '..' in value.split('/'):
        refuse('archive pattern', value)
    return operand(value)


def count(value):
    if not INTEGER.fullmatch(value) or value.startswith('-'):
        refuse('count', value)
    return value


def positive(value):
    if count(value) == '0':
        refuse('number', value)
    return value


def cpus(value):
    """Above zero: `docker update --cpus 0` changes nothing."""
    if not DECIMAL.fullmatch(value) or float(value) == 0:
        refuse('CPU count', value)
    return value


def relative_path(value):
    if value.startswith('/') or value == '' or LINE_BREAK_OR_NUL.search(value) or '..' in value.split('/'):
        refuse('relative path', value)
    return value


def entry_name(value):
    if value in ('', '.', '..') or '/' in value or LINE_BREAK_OR_NUL.search(value):
        refuse('name', value)
    return value


def tagged_path(value):
    if value[:2] not in TAGS:
        refuse('tagged path', value)
    path(value[2:])
    return value


def url(value):
    if not URL.match(value) or LINE_BREAK_OR_NUL.search(value):
        refuse('URL', value)
    return value


def word(value):
    if '\x00' in value:
        refuse('argument', value)
    return value


def text(value):
    if '\0' in value:
        refuse('value', value)
    return value

