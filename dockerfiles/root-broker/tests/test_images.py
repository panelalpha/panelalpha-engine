import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest

import support

BROKER = os.path.dirname(support.HERE)
DOCKERFILES = os.path.dirname(BROKER)


def copied(dockerfile, target):
    """The sources of every COPY into `target`, relative to the build context."""
    with open(os.path.join(DOCKERFILES, dockerfile)) as f:
        text = f.read().replace('\\\n', ' ')
    sources = []
    for line in re.findall(r'^COPY (.+)$', text, re.M):
        words = line.split()
        if words[-1].rstrip('/') == target.rstrip('/'):
            sources += words[:-1]
    return sources


class CoreImageTest(unittest.TestCase):
    def test_the_modules_core_copies_are_all_the_client_imports(self):
        modules = copied('Dockerfile-core', '/usr/local/lib/panelalpha/rootbroker/')
        self.assertTrue(modules)
        with tempfile.TemporaryDirectory() as base:
            os.makedirs(os.path.join(base, 'rootbroker'))
            for module in modules:
                shutil.copy(os.path.join(DOCKERFILES, module), os.path.join(base, 'rootbroker'))
            shutil.copy(os.path.join(BROKER, 'pa-root'), base)
            result = subprocess.run([sys.executable, '-I', os.path.join(base, 'pa-root')], capture_output=True)
        self.assertEqual(result.returncode, 64, result.stderr)
        self.assertNotIn(b'Error', result.stderr)


class BrokerImageTest(unittest.TestCase):
    def test_the_broker_image_holds_the_package_and_the_programs_it_runs(self):
        self.assertEqual(copied('Dockerfile-root-broker', '/usr/local/lib/panelalpha/rootbroker'), ['root-broker/rootbroker'])
        self.assertEqual(sorted(copied('Dockerfile-root-broker', '/usr/local/lib/panelalpha/')),
                         ['root-broker/pa-broker', 'root-broker/pa-file-stream', 'root-broker/pa-root-broker',
                          'root-broker/pa-root-owned'])

    def test_pa_broker_is_on_the_path_of_a_docker_exec(self):
        with open(os.path.join(DOCKERFILES, 'Dockerfile-root-broker')) as f:
            self.assertIn('ln -s /usr/local/lib/panelalpha/pa-broker /usr/local/bin/pa-broker', f.read())
