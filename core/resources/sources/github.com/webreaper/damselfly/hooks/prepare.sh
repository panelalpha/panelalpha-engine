#!/bin/bash
# The repo's docker-compose.override.yml (Visual Studio dev: damselfly.web,
# https ports, ~/.aspnet mounts) is still layered over a replaced compose file.
set -e
rm -f ~/project/docker-compose.override.yml
