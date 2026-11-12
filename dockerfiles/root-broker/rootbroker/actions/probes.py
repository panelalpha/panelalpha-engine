"""The `probes` area: one action per App\\System\\Privileged\\RootProbes method, in the host's namespaces only."""

import dataclasses

from .. import hostvalues, values
from . import docker
from .hosted import fixed, host, in_home
from .sequence import reads

DISK_PATHS = ('/home', '/var/tmp', '/var/lib/docker')
SYSBOX_DAEMONS = ('sysbox-fs', 'sysbox-mgr')


disk_path = values.one_of(DISK_PATHS, 'filesystem')


ACTIONS = {
    'blockDeviceParent': host(reads(hostvalues.device), lambda context, d: ['lsblk', '--nodeps', '-no', 'PKNAME', d]),
    'blockDeviceType': host(reads(hostvalues.device), lambda context, d: ['lsblk', '--nodeps', '-no', 'TYPE', d]),
    'diskFree': host(reads(disk_path), lambda context, path: ['df', '-P', path]),
    'diskFreeInKilobytes': host(reads(disk_path), lambda context, path: ['df', '-Pk', path]),
    'diskUsageInKilobytes': host(reads(values.path), lambda context, home: ['du', '-sxk', in_home(context, home)]),
    'sysboxStates': fixed('systemctl', 'show', *SYSBOX_DAEMONS, '--property=Id,LoadState,ActiveState,ActiveEnterTimestamp', '--timestamp=utc'),
    'gitVersion': fixed('git', '--version'),
    'hostImage': dataclasses.replace(docker.IMAGES['inspectImage'], host_only=True),
}
