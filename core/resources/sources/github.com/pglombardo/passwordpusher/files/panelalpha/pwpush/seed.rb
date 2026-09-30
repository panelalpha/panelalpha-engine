# Seeds the administrator once, while the user table is empty. Later password
# changes made in the UI are never overwritten by a redeploy.
email = ENV.fetch("PA_ADMIN_EMAIL")
password = ENV.fetch("PA_ADMIN_PASSWORD")

if User.exists?
  puts "[panelalpha] users present, admin not seeded"
else
  User.create!(email: email, password: password, password_confirmation: password, admin: true)
  puts "[panelalpha] seeded admin #{email}"
end
