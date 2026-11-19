"""The superadmin's password from the login the engine generated.

server.py's first start seeds superadmin, test001, test002 and test003 with
random passwords that it writes only to initial-accounts.txt next to the
database. Here, before the server starts, the database is opened with the
server's own Store, which seeds it exactly as the server would when it is new
(the server then finds it seeded and does not seed again), and the superadmin
gets HOLA_ADMIN_PASSWORD through Store.set_password, but only while it still
has the password it was seeded with: on a new database, or when the password
initial-accounts.txt lists for it still verifies. A password changed in the
app is never touched. initial-accounts.txt is written as the server writes it,
without the superadmin's line, whose seeded password no longer works.
"""
import os
import sys
from pathlib import Path

sys.path.insert(0, '/app')
import server  # noqa: E402  the app's own module; main() runs only as __main__


def say(message):
    print(f'[panelalpha] hola-calendar: {message}', file=sys.stderr, flush=True)


ADMIN = 'superadmin'
password = os.environ.get('HOLA_ADMIN_PASSWORD', '')
if not password:
    say('HOLA_ADMIN_PASSWORD is empty; the seeded password stays')
    sys.exit(0)
if os.environ.get('HOLA_ADMIN_USER', ADMIN) != ADMIN:
    say(f'HOLA_ADMIN_USER is not {ADMIN}; the code knows only that operator account')

db = os.environ.get('WORK_CALENDAR_DB', str(server.ROOT / 'data' / 'work-calendar.sqlite3'))
accounts = Path(db).parent / 'initial-accounts.txt'
store = server.Store(db)
try:
    seeded = store.initial_passwords.get(ADMIN)
    if store.initial_passwords:
        # What server.main() writes on a new database, less the superadmin.
        fd = os.open(accounts, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            f.write('哗啦待办 本地核验账户（请勿上传或公开分享）\n')
            for id, pw in store.initial_passwords.items():
                if id != ADMIN:
                    f.write(f'{id}\t{pw}\n')
    elif accounts.exists():
        for line in accounts.read_text(encoding='utf-8').splitlines():
            id, _, pw = line.partition('\t')
            if id == ADMIN and pw:
                seeded = pw
    if seeded and store.check_password(ADMIN, seeded):
        store.set_password(ADMIN, password)
        say(f'{ADMIN} now has the generated password')
    else:
        say(f'{ADMIN} no longer has its seeded password; left alone')
finally:
    store.close()
