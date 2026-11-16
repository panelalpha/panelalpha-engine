"""Shell words quoted as the engine's PHP quotes them, so a script matches the engine's byte for byte."""


def escapeshellarg(value):
    """PHP's escapeshellarg()."""
    return "'" + value.replace("'", "'\\''") + "'"
