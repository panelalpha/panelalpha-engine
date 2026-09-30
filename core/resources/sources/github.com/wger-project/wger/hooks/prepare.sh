#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password, and SECRET_KEY signs sessions.
set -e
STORE="${HOME}/.panelalpha/wger"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/wger.env" ]; then
    db="$(openssl rand -hex 24)"
    # RS256 keypair as base64-wrapped JWKs, the format of `manage.py
    # generate-jwt-keys` (no python in the account, so openssl + perl).
    jwt="$(openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 2>/dev/null \
        | openssl rsa -text -noout 2>/dev/null | perl -MMIME::Base64 -e '
        my %map = (modulus=>"n", publicExponent=>"e", privateExponent=>"d", prime1=>"p",
                   prime2=>"q", exponent1=>"dp", exponent2=>"dq", coefficient=>"qi");
        my ($cur, %hex);
        while (<STDIN>) {
            if (/^(\w+):\s*(\d+)?/ && exists $map{$1}) {
                $cur = $map{$1};
                if (defined $2) { $hex{$cur} = sprintf("%x", $2); $cur = undef; }
                next;
            }
            if (/^\s+([0-9a-f:]+)\s*$/ && $cur) { (my $h = $1) =~ s/://g; $hex{$cur} .= $h; next; }
            $cur = undef;
        }
        sub b64u { my $h = shift; $h = "0$h" if length($h) % 2; my $b = pack("H*", $h); $b =~ s/^\x00+//;
                   my $s = encode_base64($b, ""); $s =~ tr{+/}{-_}; $s =~ s/=+$//; return $s; }
        my %j = map { $_ => b64u($hex{$_}) } keys %hex;
        @j{qw(kty alg kid)} = ("RSA", "RS256", "wger");
        sub json { "{" . join(", ", map { "\"$_\": \"$j{$_}\"" } @_) . "}" }
        sub wrap { my $s = encode_base64(shift, ""); $s =~ tr{+/}{-_}; return $s; }
        print "JWT_PRIVATE_KEY=", wrap(json(qw(kty n e d p q dp dq qi alg kid))), "\n";
        print "JWT_PUBLIC_KEY=", wrap(json(qw(kty n e alg kid))), "\n";
    ')"
    case "$jwt" in *JWT_PRIVATE_KEY=ey*JWT_PUBLIC_KEY=ey*) ;; *) echo "JWT key generation failed" >&2; exit 1;; esac
    (umask 077; printf 'SECRET_KEY=%s\nPOSTGRES_PASSWORD=%s\nDJANGO_DB_PASSWORD=%s\n%s\n' \
        "$(openssl rand -hex 32)" "$db" "$db" "$jwt" > "${STORE}/wger.env")
fi
chmod 600 "${STORE}/wger.env"
