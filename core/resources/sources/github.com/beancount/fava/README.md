# Fava

Web UI for Beancount. Fava needs a ledger file to start, which a clone does not have.

- Builds upstream's `contrib/docker/Dockerfile` unchanged (current fava release from PyPI).
- `BEANCOUNT_FILE=/data/main.beancount` on the `data` volume; the one-shot `ledger`
  service creates an empty file on first start and never overwrites it.
- Put your books in that file (`docker compose -p project cp my.beancount app:/data/main.beancount`).
- No login, as upstream ships it.
