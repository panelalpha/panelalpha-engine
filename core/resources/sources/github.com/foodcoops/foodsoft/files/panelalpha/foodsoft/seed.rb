# Minimal, production-safe seed run once by init.sh on an empty database.
#
# This mirrors db/seeds/minimal.seeds.rb (the upstream "fresh install" seed),
# NOT db/seeds.rb -> small.en, which fills a demo coop with users whose password
# is "secret". The only change from minimal is that the admin password comes
# from the environment (generated once, stored 0600 in ~/.panelalpha/foodsoft/)
# instead of the hard-coded "secret", and the default article units are added so
# the catalog is usable out of the box.

nick     = ENV.fetch('FOODSOFT_ADMIN_NICK', 'admin')
email    = ENV.fetch('FOODSOFT_ADMIN_EMAIL', 'admin@example.com')
password = ENV.fetch('FOODSOFT_ADMIN_PASSWORD')

ActiveRecord::Base.transaction do
  administrators = Workgroup.create!(
    name: 'Administrators',
    description: 'System administrators.',
    role_admin: true,
    role_finance: true,
    role_article_meta: true,
    role_pickups: true,
    role_suppliers: true,
    role_orders: true
  )

  User.create!(
    nick: nick,
    first_name: 'Site',
    last_name: 'Administrator',
    email: email,
    password: password,
    groups: [administrators]
  )

  # Base lookup rows a fresh coop needs before it can record anything.
  ftc = FinancialTransactionClass.create!(name: 'Other')
  FinancialTransactionType.create!(name: 'Foodcoop', financial_transaction_class_id: ftc.id)
  SupplierCategory.create!(name: 'Other', financial_transaction_class_id: ftc.id)
  ArticleCategory.create!(name: 'Other', description: 'other, misc, unknown')

  # Default units so articles can be created from the UI immediately.
  unit_codes = ArticleUnitsLib::DEFAULT_PIECE_UNIT_CODES +
               ArticleUnitsLib::DEFAULT_METRIC_SCALAR_UNIT_CODES
  unit_codes.each { |code| ArticleUnit.create!(unit: code) }
end

puts "[panelalpha/seed] seeded Administrators workgroup and admin '#{nick}'"
