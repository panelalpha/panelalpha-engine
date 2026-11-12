"""The `lines` area: one action per method of App\\System\\Privileged\\RootDockerLines, RootComposeLines and RootFileLines.

Each is one request a caller pastes into a larger script as a line, so only that line runs as root. The image and
build lines go to the host's daemon through its Engine API; the rest are commands.
"""

from .. import values
from ..paths import EXISTING
from . import Action, Param, docker, hostapi, hostbuilds, hostimages
from .builders import script
from .compose import in_account
from .rootscripts import in_cache_registry
from .sequence import constant, flag, reads

ENGINE = ('engine',)

REMOVE_SCRIPTS = r'''rm -- "$1"/*.sh'''
REMOVE_SCRIPTS_IF_ANY = r'''rm -f -- "$1"/*.sh'''
REMOVE_PROXY_RULE_CONFIGS_IF_ANY = r'''rm -f -- "$1"/proxy-rule-*.conf'''
EMPTY_DIRECTORY = r'''rm -rf -- "$1"/* "$1"/.*'''


cache_registry_image = in_cache_registry('image to push')


def glob_in(text):
    return Action((Param(values.path, EXISTING, ENGINE, writes=True),), script(text))


IMAGES = {
    'inspectImage': docker.IMAGES['inspectImage'],
    'imageLabel': hostapi.asking(reads(values.image, values.name), hostimages.image_label),
    'pullQuietly': hostapi.asking(reads(values.image), hostimages.pull_quietly, seconds=hostimages.SECONDS),
    'pushQuietly': hostapi.asking(reads(cache_registry_image), hostimages.push_quietly, seconds=hostimages.SECONDS),
    'tag': hostapi.asking(reads(values.image, values.image), hostimages.tag_image),
    'removeImage': hostapi.asking(reads(values.image), hostimages.remove_image),
    'isRunning': docker.CONTAINERS['isRunning'],
}

BUILDS = {
    'buildFromStdin': docker.stdin_build(flag('pull'), constant(False), constant(())),
    'buildQuietly': hostapi.asking(reads(values.image, values.path), hostbuilds.quiet_build, hostbuilds.quiet_context),
}

ACTIONS = {
    **IMAGES,
    **BUILDS,
    'composePull': in_account('pull'),
    'composeBuild': in_account('build'),
    'removeScripts': glob_in(REMOVE_SCRIPTS),
    'removeScriptsIfAny': glob_in(REMOVE_SCRIPTS_IF_ANY),
    'removeProxyRuleConfigsIfAny': glob_in(REMOVE_PROXY_RULE_CONFIGS_IF_ANY),
    'emptyDirectory': glob_in(EMPTY_DIRECTORY),
}
