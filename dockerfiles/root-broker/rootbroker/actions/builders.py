"""The argv builders actions share: a shell script and its values, a command on placed paths, and the `find` commands
the `files` and `account` areas run."""


def on_paths(*command):
    return lambda placed: [*command, '--', *placed]


def script(text, shell='sh'):
    """`shell -c text shell` and the values, which the script reads as `$1...`, each item of a list value an argument
    of its own."""
    return lambda *given: [shell, '-c', text, shell, *spread(given)]


def spread(given):
    return [word for value in given for word in (value if isinstance(value, list) else [value])]


def entry_types(directory):
    return ['find', directory, '-mindepth', '1', '-maxdepth', '1', '-printf', '%y %f\\0']


def nitro_server_entries(directory, depth):
    return [
        'find', directory, '-maxdepth', depth,
        '(', '-name', 'node_modules', '-o', '-name', '.git', ')', '-prune', '-o',
        '-type', 'f', '(', '-path', '*/.output/server/index.mjs', '-o', '-path', '*/.output/server/index.js', ')',
        '-print',
    ]
