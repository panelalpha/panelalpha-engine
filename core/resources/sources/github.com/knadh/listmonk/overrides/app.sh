#!/bin/bash
cd ~/project

# info is answered locally — no running app needed
if [ "${1:-}" = 'info' ]; then
  echo '["users:list","users:add","users:delete","users:reset-password","users:sso","roles:list","install"]'
  exit 0
fi

# Helper: run a SQL query in the db container and return the result.
# The db container always has $POSTGRES_USER / $POSTGRES_PASSWORD / $POSTGRES_DB
# available in its own environment, so no credentials need to be stored elsewhere.
# SQL is passed via stdin to avoid quoting issues with single quotes in queries.
db_query() {
  printf '%s' "$1" | docker compose exec -T db sh -c \
    'PGPASSWORD=$POSTGRES_PASSWORD psql -U $POSTGRES_USER -d $POSTGRES_DB -t -A'
}

case "${1:-}" in
  users:list)
    # Query the users table directly.  Join roles to get the role name.
    # Output is a JSON array in the standard PanelAlpha format.
    db_query "
      SELECT COALESCE(json_agg(row ORDER BY row.id::int), '[]'::json)
      FROM (
        SELECT u.id::text         AS id,
               u.username         AS username,
               COALESCE(u.email, '') AS email,
               COALESCE(r.name, u.type::text) AS role
        FROM   users u
        LEFT   JOIN roles r ON r.id = u.user_role_id
        WHERE  u.type = 'user'
      ) row
    "
    ;;

  users:add) # users:add <login> <email> <password> <role>
    # Escape single quotes in each argument by doubling them (standard SQL escaping).
    login=$(printf '%s' "${2:-}" | sed "s/'/''/g")
    email=$(printf '%s' "${3:-}" | sed "s/'/''/g")
    pass=$(printf '%s' "${4:-}" | sed "s/'/''/g")
    role=$(printf '%s' "${5:-}" | sed "s/'/''/g")
    # INSERT ... SELECT from roles so that a non-existent role produces zero rows
    # rather than a cryptic NOT NULL constraint error.
    # crypt() + gen_salt('bf') comes from pgcrypto (already installed by listmonk).
    result=$(db_query "
      WITH ins AS (
        INSERT INTO users
              (username, password_login, password, email, name, type, user_role_id, status)
        SELECT '${login}',
               true,
               crypt('${pass}', gen_salt('bf')),
               '${email}',
               '${login}',
               'user',
               r.id,
               'enabled'
        FROM   roles r
        WHERE  r.type = 'user' AND r.name = '${role}'
        RETURNING id
      )
      SELECT json_build_object('id', id::text) FROM ins
    ")
    if [ -z "${result}" ]; then
      printf '{"error":"Could not create user. Does the role \"%s\" exist?"}\n' "${5:-}" >&2
      exit 1
    fi
    printf '%s\n' "${result}"
    ;;

  users:sso) # users:sso <user_id>
    # Validate: must be a positive integer.
    user_id="${2:-}"
    if ! printf '%s' "${user_id}" | grep -qE '^[0-9]+$'; then
      printf '{"error":"Invalid user ID"}\n' >&2
      exit 1
    fi
    # Insert a session row and return the cookie name/value so that the engine
    # can create a short-lived SSO token and redirect the browser to set the
    # cookie on the app domain via /panelalpha-sso.
    # - gen_random_uuid() produces the session ID (pgcrypto is pre-installed).
    # - The session data format matches what simplesessions/listmonk expects.
    db_query "
      WITH new_session AS (
        INSERT INTO sessions (id, data)
        VALUES (
          gen_random_uuid()::text,
          json_build_object('user_id', ${user_id}::int, 'oidc_token', '')::jsonb
        )
        RETURNING id
      )
      SELECT json_build_object(
        'cookie',   'session',
        'value',    (SELECT id FROM new_session),
        'redirect', '/admin'
      )
    "
    ;;

  users:delete) # users:delete <user_id>
    user_id="${2:-}"
    if ! printf '%s' "${user_id}" | grep -qE '^[0-9]+$'; then
      printf '{"error":"Invalid user ID"}\n' >&2
      exit 1
    fi
    # Delete sessions first (no ON DELETE CASCADE in listmonk schema).
    db_query "
      DELETE FROM sessions
      WHERE  (data->>'user_id')::int = ${user_id};
      DELETE FROM users WHERE id = ${user_id} AND type = 'user';
    "
    ;;

  users:reset-password) # users:reset-password <user_id> <new_password>
    user_id="${2:-}"
    if ! printf '%s' "${user_id}" | grep -qE '^[0-9]+$'; then
      printf '{"error":"Invalid user ID"}\n' >&2
      exit 1
    fi
    pass=$(printf '%s' "${3:-}" | sed "s/'/''/g")
    db_query "
      UPDATE users
      SET    password = crypt('${pass}', gen_salt('bf'))
      WHERE  id = ${user_id} AND type = 'user'
    "
    ;;

  roles:list)
    db_query "SELECT COALESCE(json_agg(name ORDER BY name), '[]'::json) FROM roles WHERE type = 'user'"
    ;;

  install) # install <url> <title> <admin_user> <admin_email> <admin_password>
    url="${2:-}"
    title="${3:-}"
    admin_user="${4:-}"
    admin_email="${5:-}"
    admin_pass="${6:-}"
    if [ -z "${url}" ] || [ -z "${admin_user}" ] || [ -z "${admin_email}" ] || [ -z "${admin_pass}" ]; then
      printf '{"error":"url, admin_user, admin_email and admin_password are required"}\n' >&2
      exit 1
    fi
    # The recipe creates a Super Admin before listmonk is reachable (start.sh),
    # so the first-time setup form is gone by the time this runs. Give the
    # panel's account the Super Admin role instead: created if it does not
    # exist, its email and password set if it does.
    login=$(printf '%s' "${admin_user}" | sed "s/'/''/g")
    email=$(printf '%s' "${admin_email}" | sed "s/'/''/g")
    pass=$(printf '%s' "${admin_pass}" | sed "s/'/''/g")
    result=$(db_query "
      WITH up AS (
        INSERT INTO users
              (username, password_login, password, email, name, type, user_role_id, status)
        SELECT '${login}', true, crypt('${pass}', gen_salt('bf')), '${email}', '${login}',
               'user', r.id, 'enabled'
        FROM   roles r
        WHERE  r.type = 'user' AND r.name = 'Super Admin'
        LIMIT  1
        ON CONFLICT (username) DO UPDATE
           SET password = EXCLUDED.password, password_login = true,
               email = EXCLUDED.email, user_role_id = EXCLUDED.user_role_id,
               status = 'enabled', updated_at = NOW()
        RETURNING id
      )
      SELECT json_build_object('id', id::text) FROM up
    ")
    if [ -z "${result}" ]; then
      printf '{"error":"Install failed: the Super Admin role does not exist yet"}\n' >&2
      exit 1
    fi
    # Persist the public root URL and site name.
    url_sql=$(printf '%s' "${url}" | sed "s/'/''/g")
    title_sql=$(printf '%s' "${title}" | sed "s/'/''/g")
    db_query "
      UPDATE settings SET value = to_json('${url_sql}'::text)   WHERE key = 'app.root_url';
      UPDATE settings SET value = to_json('${title_sql}'::text) WHERE key = 'app.site_name';
    " >/dev/null
    printf '%s\n' "${result}"
    ;;

  *)
    printf '{"error":"Unknown action: %s"}\n' "${1:-}" >&2
    exit 1
    ;;
esac
