#!/bin/bash
# The login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs. The app runs as the
# image's `mercator` user, which must be able to read the mounted scripts.
set -e
cd ~/project

chmod a+rx panelalpha
chmod a+r panelalpha/mercator-start.sh panelalpha/mercator-admin.php
