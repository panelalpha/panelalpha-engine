#!/bin/sh
# Creates SyMon's first dashboard user with the engine's login while the
# database has none, then runs the collector (the image's own command).
# `-list-users` and `-add-user` run the collector's migrations first.
if [ -n "${SYMON_ADMIN_USER:-}" ] && [ -n "${SYMON_ADMIN_PASSWORD:-}" ]; then
    # The database may still be starting (a restart of the whole stack).
    tries=30
    until users=$(./collector -list-users 2>&1); do
        tries=$((tries - 1))
        if [ "$tries" -le 0 ]; then
            echo "[panelalpha] symon: could not list the dashboard users: $users" >&2
            users=
            break
        fi
        sleep 2
    done
    case "$users" in
        "No users yet"*)
            printf '%s\n' "$SYMON_ADMIN_PASSWORD" \
                | ./collector -add-user "$SYMON_ADMIN_USER" -role admin -password-stdin \
                && echo "[panelalpha] symon: created the dashboard user '$SYMON_ADMIN_USER' with the engine's login" \
                || echo "[panelalpha] symon: could not create the dashboard user; it stays locked" >&2
            ;;
        "") ;;
        *) echo "[panelalpha] symon: the dashboard has users, nothing to create" ;;
    esac
fi
exec ./collector
