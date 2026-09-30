# Replaces the seeds' fixed admin password (admin@claper.co / claper) with the
# engine's one (~/.panelalpha/app-credentials.env). A no-op once the default no longer verifies.
Application.load(:claper)
Application.ensure_all_started(:ssl)

{:ok, _, _} =
  Ecto.Migrator.with_repo(Claper.Repo, fn _repo ->
    pw = System.fetch_env!("CLAPER_ADMIN_PASSWORD")

    case Claper.Accounts.get_user_by_email_and_password("admin@claper.co", "claper") do
      nil ->
        IO.puts("[panelalpha] default admin password already replaced")

      user ->
        {:ok, _} = Claper.Accounts.reset_user_password(user, %{password: pw, password_confirmation: pw})
        IO.puts("[panelalpha] default admin password replaced")
    end
  end)
