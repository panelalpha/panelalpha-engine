# MoinMoin (github.com/moinwiki/moin)

MoinMoin 2 wiki, on port 8000.

## Deploying

Nothing is required. The first deploy takes a few minutes: it installs moin
2.0.0b5 from PyPI and builds the wiki (help pages, welcome page, search
index); the deploy waits until the wiki answers.

First run is upstream's: the shipped `wikiconfig.py` allows registration only
by a superuser and names the superuser `YOUR-SUPER-USER-NAME`. To administer
the wiki, open a shell in the app container
(`docker compose -p project exec app sh`), `cd /srv/moin`, create an account
with `/opt/moin-venv/bin/moin account-create --name <name> --email <email>
--password <password>`, and put `<name>:superuser` in `acl_functions` in
`/srv/moin/wikiconfig.py` (then redeploy or restart).

## What the recipe does

- `overrides/docker-compose.yml` runs `python:3.14-slim-trixie` with
  `files/moin-start.sh`, which on first boot installs `moin==2.0.0b5` and
  `hypercorn==0.17.3` (upstream's contrib/docker pin) into a venv on the
  `moin-venv` volume, then creates the instance in `/srv/moin` on the
  `moin-wiki` volume (`moin create-instance`, then `--full`) and replaces the
  placeholder `SECRET_KEY` with a random one. Both survive redeploys.
- hypercorn uses the repository's own `contrib/docker/config.toml`.
- `ready` holds the deploy until `/Home` answers.
