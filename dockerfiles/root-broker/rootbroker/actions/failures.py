"""What a native action makes of what goes wrong when it runs: a line on its stderr and an exit code."""

import functools


class Failures:
    """Rows of (exception types, wording, exit code), the first that catches an exception wins. The wording formats
    `e` and the caller's words; the code may be a function of the exception."""

    def __init__(self, *rows):
        self.rows = rows
        self.caught = tuple(kind for types, _, _ in rows for kind in (types if isinstance(types, tuple) else (types,)))

    def answered(self, output, error, **words):
        """The exit code for `error`, once its line is on the output's stderr."""
        wording, code = next((wording, code) for types, wording, code in self.rows if isinstance(error, types))
        output.stderr(f'{wording.format(e=error, **words)}\n'.encode())
        return code(error) if callable(code) else code

    def run(self, output, work, *values, **words):
        """`work(*values)`'s exit code, or that of the row that catches what it raises."""
        try:
            return work(*values)
        except self.caught as e:
            return self.answered(output, e, **words)

    def finishing(self, work, *values, **words):
        """The native run of `work(*values)`: exit 0 once it returns, else the code for what it raised."""
        return functools.partial(self.finish, work, values, words)

    def writing(self, work, *values, **words):
        """`finishing` of a work that writes the action's output: `work(output, *values)`."""
        return functools.partial(self.write, work, values, words)

    def finish(self, work, values, words, output):
        try:
            work(*values)
        except self.caught as e:
            return self.answered(output, e, **words)
        return 0

    def write(self, work, values, words, output):
        return self.finish(work, (output, *values), words, output)
