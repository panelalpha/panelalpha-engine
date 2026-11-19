#!/bin/bash
# Versus Incident copies every value of config/config.yaml over its environment
# (viper Set, before AutomaticEnv), so the compose file's PUBLIC_HOST is ignored
# while the file says `public_host: ''`. The file's values go through
# os.ExpandEnv, so it reads the variable instead; the image copies config/ in
# at build time, after this hook. A value someone set in the file stays.
set -e
cd ~/project

sed -i "s/^public_host: ''$/public_host: \${PUBLIC_HOST}/" config/config.yaml
