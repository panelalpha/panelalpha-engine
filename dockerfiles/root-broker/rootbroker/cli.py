"""What the docker CLI printed, written as it wrote it: lines, its templates' JSON, sizes, IDs and names."""

import json

SHORT_ID = 12
INDENT = 4
SIZE_DIGITS = 3
UNITS = ('B', 'kB', 'MB', 'GB', 'TB', 'PB')


def line(text, errors='strict'):
    return f'{text}\n'.encode('utf-8', errors)


def printed(output, texts, errors='strict'):
    """Each text a line on stdout, as the CLI printed it; 0, its exit code."""
    for text in texts:
        output.stdout(line(text, errors))
    return 0


def json_line(value, ascii=False):
    """`value` as JSON without spaces on one line, as `{{json .}}` printed it, or escaped to ASCII."""
    return line(json.dumps(value, ensure_ascii=ascii, separators=(',', ':')))


def go_json(value):
    """`value` as Go's `json` template function writes it: no spaces, a map's keys sorted."""
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=False)


def indented(value, ascii=False):
    """`value` as `docker inspect` prints it, or escaped to ASCII."""
    return json.dumps(value, indent=INDENT, ensure_ascii=ascii)


def cpu_percent(stats):
    """`docker stats`' CPU percent of one stats sample: the container's CPU time since the daemon's previous sample over
    the host's, times the online CPUs; a figure that is no number reads as 0."""
    stats = stats if isinstance(stats, dict) else {}
    used = figure(stats, 'cpu_stats', 'cpu_usage', 'total_usage') - figure(stats, 'precpu_stats', 'cpu_usage', 'total_usage')
    system = figure(stats, 'cpu_stats', 'system_cpu_usage') - figure(stats, 'precpu_stats', 'system_cpu_usage')
    cpus = figure(stats, 'cpu_stats', 'online_cpus')
    if cpus <= 0:
        cpus = len(((stats.get('cpu_stats') or {}).get('cpu_usage') or {}).get('percpu_usage') or ())
    return used / system * cpus * 100 if system > 0 and used > 0 else 0.0


def figure(record, *keys):
    for key in keys:
        record = record.get(key) if isinstance(record, dict) else None
    return record if isinstance(record, (int, float)) and not isinstance(record, bool) else 0


def size(count, digits=SIZE_DIGITS):
    """A byte count as the docker CLI prints it: decimal units, `digits` significant digits."""
    value, unit = float(count), 0
    while value >= 1000 and unit < len(UNITS) - 1:
        value /= 1000
        unit += 1
    return f'{value:.{digits}g}{UNITS[unit]}'


def short_id(listed):
    return str(listed.get('Id', ''))[:SHORT_ID]


def names(listed):
    """A listed container's names as `{{.Names}}` joins them."""
    return ','.join(name.removeprefix('/') for name in listed.get('Names') or ())
