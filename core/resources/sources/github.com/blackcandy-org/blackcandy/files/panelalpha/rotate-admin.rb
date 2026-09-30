# Replaces the seeded admin (db/seeds.rb: admin@admin.com / foobar). Acts only
# while that default still authenticates, so a login changed in the app is kept.
email = ENV.fetch("BC_ADMIN_EMAIL")
password = ENV.fetch("BC_ADMIN_PASSWORD")

user = User.find_by(email: "admin@admin.com")
if user&.authenticate("foobar")
  user.update!(email: email, password: password)
  puts "[panelalpha] seeded admin rotated to #{email}"
else
  puts "[panelalpha] no admin with the default login; nothing to do"
end
