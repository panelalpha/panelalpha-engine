#!/bin/bash
# Runs after the image's entrypoint has written database.yml and run
# db:migrate (a new database gets Redmine's built-in admin / admin): sets the
# engine's login on that admin while it still has the password `admin`, then
# starts the server as the image's own CMD does.
set -e
if [ -n "${REDMINE_ADMIN_PASSWORD:-}" ]; then
    rails runner - <<'RUBY' || echo "[panelalpha] redmine: could not set the admin login; it stays admin/admin" >&2
login = ENV['REDMINE_ADMIN_USERNAME'].presence || 'admin'
user = User.find_by_login(login) || User.find_by_login('admin')
if user && user.admin? && user.check_password?('admin')
  user.login = login
  user.password = user.password_confirmation = ENV['REDMINE_ADMIN_PASSWORD']
  user.must_change_passwd = false
  user.save!
  puts "[panelalpha] redmine: the admin's login is the one the engine generated"
end
RUBY
fi
exec rails server -b 0.0.0.0
