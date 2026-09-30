#!/bin/sh
# Upstream's install from the release tarball (README: configure, make install,
# make initdb) on Best Practical's own RT base image, then rt-server.
set -e
V=6.0.3
SHA=117b53e7a46e82ade662050d98920f1255865ca873d6bec80036085b17dd3dc6
if [ "$(cat /opt/rt6/.pa-installed 2>/dev/null)" != "$V" ]; then
    cd /tmp
    curl -fsSL -o rt.tgz "https://download.bestpractical.com/pub/rt/release/rt-$V.tar.gz"
    echo "$SHA  rt.tgz" | sha256sum -c -
    tar xzf rt.tgz
    cd "rt-$V"
    ./configure --prefix=/opt/rt6 --with-db-type=Pg --with-db-host=db \
        --with-db-port=5432 --with-db-database=rt6 --with-db-rt-user=rt_user \
        --with-web-user=www-data --with-web-group=www-data --with-rt-group=www-data
    make install
    echo "$V" > /opt/rt6/.pa-installed
    cd / && rm -rf "/tmp/rt-$V" /tmp/rt.tgz
fi
cp /panelalpha/RT_SiteConfig.pm /opt/rt6/etc/RT_SiteConfig.d/50-panelalpha.pm
# Initialise only a database that does not exist yet (the image has no psql).
rc=0
perl -MDBI -e '$d = DBI->connect("dbi:Pg:dbname=postgres;host=db", "postgres",
    $ENV{POSTGRES_PASSWORD}, {RaiseError => 1, PrintError => 0});
    exit($d->selectrow_array("select count(*) from pg_database where datname = ?",
    undef, "rt6") ? 0 : 3)' || rc=$?
if [ "$rc" = 3 ]; then
    /opt/rt6/sbin/rt-setup-database --action init \
        --dba postgres --dba-password "$POSTGRES_PASSWORD"
elif [ "$rc" != 0 ]; then
    exit "$rc"
fi
exec setpriv --reuid=www-data --regid=www-data --init-groups \
    /opt/rt6/sbin/rt-server --port 8080 --max-workers 3
