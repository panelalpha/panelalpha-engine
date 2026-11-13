import os
import re
import subprocess
import sys
import unittest

import support

BROKER = os.path.dirname(support.HERE)
DOCKERFILES = os.path.dirname(BROKER)
ENGINE = os.path.dirname(DOCKERFILES)
MOUNT = './dockerfiles/root-broker:/usr/local/lib/panelalpha:ro'


def dockerfile(name):
    with open(os.path.join(DOCKERFILES, name)) as f:
        return f.read().replace('\\\n', ' ')


def service_volumes(name):
    """The volume lines of one service in docker-compose.yml."""
    with open(os.path.join(ENGINE, 'docker-compose.yml')) as f:
        text = f.read()
    block = re.search(r'^  ' + re.escape(name) + r':\n(.*?)(?=^  [a-z][a-z0-9-]*:$)', text, re.M | re.S).group(1)
    return re.findall(r'^      - (\S+)$', block, re.M)


class CodeIsMountedTest(unittest.TestCase):
    def test_no_image_copies_the_broker_s_code(self):
        for name in ('Dockerfile-core', 'Dockerfile-root-broker'):
            text = dockerfile(name)
            self.assertNotIn('COPY root-broker', text, name)
            self.assertNotIn('compileall', text, name)

    def test_core_and_the_broker_run_it_from_the_engine_tree_read_only(self):
        for service in ('core', 'root-broker'):
            self.assertIn(MOUNT, service_volumes(service), service)

    def test_the_programs_mounted_are_executable_and_the_client_runs_from_there(self):
        for program in ('pa-root', 'pa-root-broker', 'pa-broker', 'pa-file-stream', 'pa-root-owned'):
            self.assertTrue(os.access(os.path.join(BROKER, program), os.X_OK), program)
        result = subprocess.run([sys.executable, '-I', os.path.join(BROKER, 'pa-root')], capture_output=True)
        self.assertEqual(result.returncode, 64, result.stderr)
        self.assertNotIn(b'Error', result.stderr)

    def test_pa_broker_is_on_the_path_of_a_docker_exec(self):
        self.assertIn('ln -s /usr/local/lib/panelalpha/pa-broker /usr/local/bin/pa-broker',
                      dockerfile('Dockerfile-root-broker'))
