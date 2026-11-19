# Nepal Compliance (github.com/yarsa/nepal-compliance)

A Frappe app: ERPNext and HRMS with Nepal's payroll, tax and fiscal-year
rules. The repository ships frappe_docker's compose layout (`compose.yaml`)
on upstream's published image `yarsalabs/nepal-compliance`, with MariaDB, two
Redis and a one-shot `create-site` that runs `bench new-site`; the stack is
used as it is.

## Deploying

Deploy as is and wait for `create-site` (3 to 5 minutes on the first deploy:
it installs ERPNext, HRMS and the app). Open `/login` and sign in as
`Administrator` with `ADMIN_PASSWORD`, the password the engine generates: read
it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## Why a recipe

`create-site` sets the Administrator's password from `${ADMIN_PASSWORD}`, and
the `.env` the engine makes from `.env.example` has it as the published
`your-admin-password`. So every deploy was a site anyone could sign in to as
Administrator.

## What the recipe does

`overrides/docker-compose.override.yml` changes only `create-site`: it reads
the engine's `app-credentials.env` (`env_file`) and runs upstream's own command
with two changes:

- `bench new-site --admin-password` gets the generated password, and runs only
  while `sites/frontend` does not exist (upstream runs it on every start and
  lets it fail);
- a site that exists and whose Administrator still has the published
  `your-admin-password` (one created before this recipe) gets the generated
  password with Frappe's own `bench set-admin-password`. Any other password,
  one changed in the app included, is left alone.

`bench build --app nepal_compliance` still runs on every start, as upstream's
does.

The credentials field is named `ADMIN_PASSWORD`, upstream's own variable, so a
project that sets `ADMIN_PASSWORD` in its environment keeps that password (the
engine then returns it). `NEPAL_COMPLIANCE_ADMIN_USER` is always
`Administrator`, Frappe's built-in superuser; nothing reads it.

The other placeholders in `.env.example` (`DB_ROOT_PASSWORD`,
`MARIADB_ROOT_PASSWORD`) are unchanged: MariaDB is reachable only on the
stack's own network.

Checked locally (2026-11-19, fbb9e95b, `yarsalabs/nepal-compliance:latest`,
the repository's compose.yaml with this override): `create-site` made the site
with the generated password; `POST /api/method/login` as Administrator with it
answered "Logged In", with `your-admin-password` "Invalid login credentials". A
restart and a redeploy (volumes kept) ran `create-site` again without touching
the site; a site set back to the published password got the generated one;
a password changed in the app (`update_password`) survived a redeploy.
