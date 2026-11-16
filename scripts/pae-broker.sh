#!/usr/bin/env bash

cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")/.."

# A terminal only for --pty on one; each --env NAME passes its value from this shell, never on a command line.
exec_args=(-T)
rest=("$@")
[[ ${rest[0]-} == --host ]] && rest=("${rest[@]:1}")
if [[ ${rest[0]-} == --pty ]]; then
    rest=("${rest[@]:1}")
    [[ -t 0 ]] && exec_args=()
fi
while [[ ${#rest[@]} -ge 2 && ${rest[0]} == --env ]]; do
    [[ ${rest[1]} =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] && exec_args+=(-e "${rest[1]}")
    rest=("${rest[@]:2}")
done

exec docker compose exec "${exec_args[@]}" root-broker pa-broker "$@"
