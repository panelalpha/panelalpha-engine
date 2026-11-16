import os
import unittest

import actionsdoc

DOCUMENT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'ACTIONS.md')


class ActionsDocTest(unittest.TestCase):
    def test_actions_md_is_what_the_action_table_says(self):
        with open(DOCUMENT) as f:
            self.assertEqual(f.read(), actionsdoc.document(), 'regenerate it: cd tests && python3 actionsdoc.py > ../ACTIONS.md')

    def test_every_action_says_what_it_does(self):
        self.assertNotIn('| — |', actionsdoc.document())
