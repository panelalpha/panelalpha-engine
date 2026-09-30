#!/bin/bash
# An existing .env stops the engine seeding it from .env.example, whose values
# (a relative database path off the volume, parent-domain cookies) would reach
# the container; .env then holds only the project's own env vars.
set -e
touch ~/project/.env
