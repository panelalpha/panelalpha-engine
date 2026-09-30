#!/bin/sh
# phpLDAPadmin is a UI for an existing directory; without LDAP_HOST every page 500s.
if [ -z "${LDAP_HOST:-}" ]; then
    echo "phpldapadmin: LDAP_HOST is not set. Set the project environment variable LDAP_HOST to your LDAP server (plus LDAP_PORT, LDAP_USERNAME / LDAP_PASSWORD for a bind DN and LDAP_BASE_DN if it needs them), then redeploy." >&2
    exit 1
fi
echo "phpldapadmin: LDAP_HOST=${LDAP_HOST}"
