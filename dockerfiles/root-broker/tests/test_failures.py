import unittest

import support  # noqa: F401
from rootbroker.actions.failures import Failures
from rootbroker.output import Output

FAILURES = Failures((KeyError, 'no {e} in {where}', 3), (OSError, '{e.strerror}', lambda e: e.errno))


class Written(Output):
    def __init__(self):
        self.out, self.err = b'', b''

    def stdout(self, data):
        self.out += data

    def stderr(self, data):
        self.err += data


def ran(native):
    output = Written()
    return native(output), output.out, output.err


class FinishingTest(unittest.TestCase):
    def test_a_work_that_takes_no_output_gets_only_its_values(self):
        taken = []
        self.assertEqual(ran(FAILURES.finishing(lambda *given: taken.append(given) or 9, 'a', 'b')), (0, b'', b''))
        self.assertEqual(taken, [('a', 'b')])

    def test_a_work_that_writes_gets_the_output_first(self):
        native = FAILURES.writing(lambda output, *given: output.stdout(' '.join(given).encode()), 'a', 'b')
        self.assertEqual(ran(native), (0, b'a b', b''))

    def test_what_a_work_raises_is_its_rows_line_and_code(self):
        def missing(*given):
            raise KeyError('x')

        def failing(output):
            output.stdout(b'part')
            raise OSError(5, 'Input/output error')

        self.assertEqual(ran(FAILURES.finishing(missing, where='y')), (3, b'', b"no 'x' in y\n"))
        self.assertEqual(ran(FAILURES.writing(failing)), (5, b'part', b'Input/output error\n'))


if __name__ == '__main__':
    unittest.main()
