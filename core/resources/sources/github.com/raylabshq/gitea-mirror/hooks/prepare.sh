#!/bin/bash
# Runs after the clone, before `docker compose up`. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook; the one-shot init service
# reads it from there. Nothing else to prepare.
set -e
