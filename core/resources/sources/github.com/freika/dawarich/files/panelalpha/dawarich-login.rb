# Run by dawarich-login.sh with `bin/rails runner`, after db:seed. The seed's
# admin has the password `safepassword`; while an admin still has it, give it
# the engine's email and password. A password changed in Dawarich is never
# touched.
email = ENV.fetch('DAWARICH_ADMIN_EMAIL', '').strip
password = ENV.fetch('DAWARICH_ADMIN_PASSWORD')
User.where(admin: true).order(:id).each do |user|
  next unless user.valid_password?('safepassword')

  user.email = email unless email.empty? || User.where.not(id: user.id).exists?(email: email)
  user.password = password
  user.password_confirmation = password
  user.save!
  puts "[panelalpha] dawarich: the admin's login is the one the engine generated (#{user.email})"
end
