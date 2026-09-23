#!/bin/bash
# The site is ~/.panelalpha/apaxy/files, not ~/project: a rebuild wipes
# ~/project and an owner's uploaded files are this application's whole content.
# The home itself is root-owned, so ~/.panelalpha is the only writable place
# left outside ~/project.
set -e

SHARE="$HOME/.panelalpha/apaxy/files"
SRC="$HOME/project/apaxy"
STATE="$HOME/.panelalpha/apaxy"

mkdir -p "$SHARE" "$STATE"

# Theme assets, replaced every deploy so an upstream update reaches the site.
# Only ever theme/ and .htaccess -- never anything the owner put here.
rm -rf "$SHARE/theme"
cp -r "$SRC/theme" "$SHARE/theme"

# {FOLDERNAME} is apaxy's placeholder for the URL path it is installed under.
# It is the document root here, so it resolves to the empty string.
sed -i 's|{FOLDERNAME}||g; s|{HEADER-MESSAGE}||g; s|{FOOTER-MESSAGE}||g' "$SHARE"/theme/*.html
sed 's|{FOLDERNAME}||g' "$SRC/htaccess.txt" > "$SHARE/.htaccess"

# mod_autoindex writes the <head> itself, and its title is a fixed
# "Index of /" -- the exact string the engine's no-directory-listing check
# reads as "this server found no page". Suppressing the preamble hands the
# whole document to HeaderName below, which is also how the site gets a name.
cat >> "$SHARE/.htaccess" <<'HTACCESS'

# --- PanelAlpha ---

# HeaderName supplies <html>/<head> from here on, so IndexStyleSheet and
# IndexHeadInsert above no longer emit anything and header.html carries them.
IndexOptions +SuppressHTMLPreamble

# The listing is the site. Nothing in it is a program, and a symlink must not
# be able to point out of the document root.
Options +Indexes -FollowSymLinks -ExecCGI -Includes

# Support files are not content. Upstream's own "/theme" never matches -- an
# IndexIgnore pattern is tested against the bare filename.
IndexIgnore .htaccess theme
HTACCESS

cat > "$SHARE/theme/header.html" <<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Shared files</title>
<link rel="shortcut icon" href="/theme/favicon.ico" />
<link rel="stylesheet" href="/theme/style.css" type="text/css" />
</head>
<body>
<div class="wrapper">

<ol class="breadcrumb" id="breadcrumb">
</ol>

<input type="search" id="filter" placeholder="filter content" />
HTML

# With the preamble suppressed, mod_autoindex stops emitting the closing tags
# too, so the footer has to carry them.
printf '\n</body>\n</html>\n' >> "$SHARE/theme/footer.html"

# First deploy only: upstream's own note saying where files go, so the site is
# not an empty page. Never re-created, or deleting it would not stick.
if [ ! -e "$STATE/seeded" ]; then
    cp "$HOME/project/share/PLACE_YOUR_FILES_HERE.txt" "$SHARE/" 2>/dev/null || true
    touch "$STATE/seeded"
fi

# Upstream's image serves as uid 1000; the account is not uid 1000, so the
# document root has to be world-readable for Apache to read it at all.
chmod -R a+rX "$SHARE"
