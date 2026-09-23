#!/bin/bash
# Install-only. Runs after the laravel recipe's migrate. Seeds the reference
# data the app cannot work without and creates one owner account. Guarded so a
# second install phase over live data never duplicates rows (the app's own
# seeders use insert(), which is not idempotent) and never resets the owner.
set -e
cd /app

# The container runs as the account uid with HOME=/ (not writable), so psysh
# (artisan tinker) fails to create its config dir and exits non-zero, which
# would abort this script. Point HOME at a writable dir.
export HOME=/tmp

PA=/app/.pa-data
mkdir -p "$PA"

# Owner password: generated once, kept 0600 on the durable mount.
PWFILE="$PA/owner-password"
if [ ! -s "$PWFILE" ]; then
  ( umask 077; head -c 18 /dev/urandom | base64 | tr -d '/+=' | cut -c1-24 > "$PWFILE" )
  chmod 600 "$PWFILE"
fi
OWNER_EMAIL="${PA_OWNER_EMAIL:-owner@genealogy.local}"

# Reference data (Settings, Genders) only when the tables are empty. A person
# cannot be created without the gender list, so this is not optional.
php artisan tinker --execute='
if (\App\Models\Setting::count() === 0) { (new \Database\Seeders\SettingSeeder())->run(); echo "settings seeded\n"; } else { echo "settings already present\n"; }
if (\App\Models\Gender::count() === 0) { (new \Database\Seeders\GenderSeeder())->run(); echo "genders seeded\n"; } else { echo "genders already present\n"; }
'

# Owner account: is_developer=true (the only way to get the admin/user-manager
# role — it is a database column, never granted by registration), with its own
# personal team, mirroring the app's CreateNewUser action.
OWNER_EMAIL="$OWNER_EMAIL" OWNER_PASSWORD="$(cat "$PWFILE")" php artisan tinker --execute='
$e = getenv("OWNER_EMAIL"); $p = getenv("OWNER_PASSWORD");
if (\App\Models\User::where("email", $e)->exists()) {
    echo "owner already exists\n";
} else {
    $u = \App\Models\User::create([
        "firstname"    => "Family",
        "surname"      => "Owner",
        "email"        => $e,
        "password"     => \Illuminate\Support\Facades\Hash::make($p),
        "language"     => "en",
        "timezone"     => "UTC",
        "is_developer" => true,
    ]);
    $t = $u->ownedTeams()->save(\App\Models\Team::forceCreate([
        "user_id"       => $u->id,
        "name"          => "Team " . $u->name,
        "personal_team" => true,
    ]));
    $u->forceFill(["current_team_id" => $t->id])->save();
    echo "owner created: " . $e . "\n";
}
'

echo "genealogy seed done (owner: $OWNER_EMAIL, password in ~/.panelalpha/genealogy/owner-password)"
