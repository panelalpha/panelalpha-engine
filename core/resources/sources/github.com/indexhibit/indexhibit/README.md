# Indexhibit

Portfolio CMS, plain PHP + MySQL (mysqli). The plain deploy answered
"Database is not installed." with no database to install into, and anything
the installer writes into the checkout is wiped by the next deploy.

- `database: mysql`: the installer at `/ndxzstudio/install.php` takes the
  `DB_*` credentials the engine shows for the project.
- `ndxzsite/config/` (the installer writes `config.php` there) and `files/`
  (uploads) are seeded once into `~/.panelalpha/indexhibit/` and mounted back.
