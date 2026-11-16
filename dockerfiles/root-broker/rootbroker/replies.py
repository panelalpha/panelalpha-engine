"""What the engine keeps of an account daemon's replies, checked field by field.

A wrong type, an oversize field or a missing `Required` one fails the whole reply; unread fields are dropped. The
host's own daemon is root's, so its replies are taken `unchecked`, or `unchecked_object` when read as an object.
"""

import re

ID = re.compile(r'[0-9a-f]{64}')
IMAGE_ID = re.compile(r'sha256:[0-9a-f]{64}')


class Unexpected(ValueError):
    def __init__(self, where):
        super().__init__(where)
        self.where = where or 'reply'


def at(where, key):
    return f'{where}.{key}' if where else str(key)


class Shape:
    """`take` keeps what a value at `where` may hold; `check` takes a whole reply. A Record may lack a field whose shape
    is not `required`."""

    required = False

    def check(self, value):
        return self.take(value, '')


class Text(Shape):
    def __init__(self, limit=4096, pattern=None):
        self.limit = limit
        self.pattern = pattern

    def take(self, value, where):
        if not isinstance(value, str) or len(value) > self.limit:
            raise Unexpected(where)
        if self.pattern is not None and not self.pattern.fullmatch(value):
            raise Unexpected(where)
        return value


class Integer(Shape):
    def take(self, value, where):
        if not isinstance(value, int) or isinstance(value, bool):
            raise Unexpected(where)
        return value


class Boolean(Shape):
    def take(self, value, where):
        if not isinstance(value, bool):
            raise Unexpected(where)
        return value


class Listing(Shape):
    def __init__(self, item, limit):
        self.item = item
        self.limit = limit

    def take(self, value, where):
        if not isinstance(value, list) or len(value) > self.limit:
            raise Unexpected(where)
        return [self.item.take(item, at(where, index)) for index, item in enumerate(value)]


class Mapping(Shape):
    def __init__(self, value, limit, key=None):
        self.value = value
        self.limit = limit
        self.key = key or Text(1024)

    def take(self, value, where):
        if not isinstance(value, dict) or len(value) > self.limit:
            raise Unexpected(where)
        return {self.key.take(k, where): self.value.take(v, at(where, k)) for k, v in value.items()}


class Record(Shape):
    def __init__(self, **fields):
        self.fields = fields

    def take(self, value, where):
        if not isinstance(value, dict):
            raise Unexpected(where)
        lacking = next((name for name, shape in self.fields.items() if shape.required and name not in value), None)
        if lacking is not None:
            raise Unexpected(at(where, lacking))
        return {name: shape.take(value[name], at(where, name)) for name, shape in self.fields.items() if name in value}


class Required(Shape):
    """A field a Record must hold."""

    required = True

    def __init__(self, shape):
        self.shape = shape

    def take(self, value, where):
        return self.shape.take(value, where)


class Nullable(Shape):
    def __init__(self, shape):
        self.shape = shape

    def take(self, value, where):
        return None if value is None else self.shape.take(value, where)


class Empty(Shape):
    """Docker's `{}` placeholder, as in ExposedPorts; whatever it holds is dropped."""

    def take(self, value, where):
        if not isinstance(value, dict):
            raise Unexpected(where)
        return {}


TEXT = Text()
NAME = Text(256)
LONG_TEXT = Text(1 << 16)
INTEGER = Integer()
BOOLEAN = Boolean()
LABELS = Nullable(Mapping(LONG_TEXT, 1024))
WORDS = Nullable(Listing(LONG_TEXT, 4096))
CONTAINERS = 8192
IMAGES = 8192
CACHE_RECORDS = 1 << 16

HEALTH = Record(
    Status=NAME,
    FailingStreak=INTEGER,
    Log=Nullable(Listing(Record(Start=NAME, End=NAME, ExitCode=INTEGER, Output=LONG_TEXT), 64)),
)

container = Record(
    Id=Text(64, ID),
    Name=NAME,
    Created=NAME,
    Image=Text(71, IMAGE_ID),
    RestartCount=INTEGER,
    State=Record(
        Status=NAME,
        Running=BOOLEAN,
        Restarting=BOOLEAN,
        ExitCode=INTEGER,
        Pid=INTEGER,
        StartedAt=NAME,
        FinishedAt=NAME,
        Health=Nullable(HEALTH),
    ),
    Config=Record(Image=TEXT, Labels=LABELS, Tty=BOOLEAN),
    NetworkSettings=Record(
        Networks=Nullable(Mapping(Record(IPAddress=NAME), 256)),
        Ports=Nullable(Mapping(Nullable(Listing(Record(HostIp=NAME, HostPort=NAME), 64)), 1024)),
    ),
    Mounts=Nullable(Listing(Record(Type=NAME, Name=NAME, Source=TEXT, Destination=TEXT), 1024)),
).check

containers = Listing(Record(
    Id=Required(Text(64, ID)),
    Names=Nullable(Listing(NAME, 64)),
    Image=TEXT,
    ImageID=NAME,
    Command=LONG_TEXT,
    Created=INTEGER,
    State=NAME,
    Status=NAME,
    Labels=LABELS,
    Mounts=Nullable(Listing(Record(Name=NAME, Source=TEXT, Driver=NAME), 1024)),
    NetworkSettings=Nullable(Record(Networks=Nullable(Mapping(Empty(), 256)))),
), CONTAINERS).check

top = Record(
    Titles=Nullable(Listing(TEXT, 64)),
    Processes=Nullable(Listing(Listing(TEXT, 64), 4096)),
).check

image = Record(
    Id=Text(71, IMAGE_ID),
    Created=NAME,
    RepoTags=Nullable(Listing(TEXT, 1024)),
    Config=Nullable(Record(
        Cmd=WORDS,
        Entrypoint=WORDS,
        Env=WORDS,
        ExposedPorts=Nullable(Mapping(Empty(), 1024)),
        User=NAME,
        WorkingDir=TEXT,
        Labels=LABELS,
    )),
).check

images = Listing(Record(
    Id=Text(71, IMAGE_ID),
    RepoTags=Nullable(Listing(TEXT, 1024)),
    RepoDigests=Nullable(Listing(TEXT, 1024)),
), IMAGES).check

VOLUME = Record(Name=NAME, Driver=NAME, Labels=LABELS)
history = Listing(Record(Id=TEXT), 1024).check
created = Record(Id=Required(Text(64, ID))).check
exec_state = Record(ExitCode=Nullable(INTEGER), Running=BOOLEAN).check
waited = Record(StatusCode=Required(INTEGER)).check
volume = VOLUME.check
volumes = Record(Volumes=Nullable(Listing(VOLUME, 8192))).check
pruned = Record(SpaceReclaimed=Nullable(INTEGER)).check
build_cache = Record(BuildCache=Nullable(Listing(Record(Size=INTEGER), CACHE_RECORDS))).check


def unchecked(reply):
    """A reply of the host's own daemon, as it came."""
    return reply


def unchecked_object(reply):
    """A reply of the host's own daemon, as it came, which must be a JSON object: an empty body is not one."""
    if not isinstance(reply, dict):
        raise Unexpected('')
    return reply
