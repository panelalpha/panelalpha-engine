#!/bin/bash
# ~/.panelalpha is private to the account, so the app container is given only
# ~/.panelalpha/galette (overrides/docker-compose.override.yml). Create it here, as
# the account, before compose binds it: a missing bind source would be made by
# Docker as root, and Galette (running as the account) could not write into it.
set -e
mkdir -p "${HOME}/.panelalpha/galette"

cd "${HOME}/project"

# Two layouts answer to this repository. Up to the 1.2 tags composer.json sits in
# galette/; from the develop branch on it sits at the repository root with
# `vendor-dir: galette/vendor/` and its autoload paths prefixed with galette/.
# app_root is galette either way, so on the newer layout write the same manifest
# into galette/ with those paths rebased onto it. Only autoload and vendor-dir
# change, neither of which is in the lock's content-hash, so the lock still matches.
if [ ! -f galette/composer.json ] && [ -f composer.json ]; then
	sed -e 's#"galette/includes/#"includes/#g' \
		-e 's#"galette/lib/#"lib/#g' \
		-e 's#"galette/vendor/\{0,1\}"#"vendor/"#g' \
		composer.json > galette/composer.json
	[ -f composer.lock ] && cp composer.lock galette/composer.lock
	echo "[galette] composer.json is at the repository root; rebased it into galette/" >&2
fi

# Galette's own console, for the headless install (files/galette/panelalpha/
# galette-setup.sh). bin/ sits outside galette/, which is all the container gets.
if [ -f bin/console ]; then
	cp bin/console galette/panelalpha/console
fi
