#!/bin/bash
# Run by logrotate in the root broker's container once ModSecurity's audit log is rotated: the web server in
# sites-http restarts, through the host's Engine API, and writes the new file.
PYTHONPATH=/usr/local/lib/panelalpha exec python3 -m rootbroker.logrotation modsecurity
