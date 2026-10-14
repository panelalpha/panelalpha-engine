#!/usr/bin/env bash
# The host firewall the engine manages. FIREWALL_PROVIDER in the engine's .env
# picks scripts/firewall/<provider>.sh, the same key core reads to pick the
# provider the API talks to (App\System\Firewall\FirewallFactory). ufw is the
# only provider today.
#
#   firewall.sh --install     set up, migrating a host off CSF; run by the installers
#   firewall.sh --apply       re-apply what lives outside the provider's own rules
#                             (core runs this when it starts)
#   firewall.sh --fail2ban    re-apply the login bans' settings (the trusted list)
#   firewall.sh --unhook      remove the engine's hooks, leave the firewall on
#                             (uninstalling the engine)
#   firewall.sh --uninstall   turn the provider off and remove the engine's hooks
#   firewall.sh --refused     the stored rules the provider would not load, one per
#                             line: the rule, a tab, why (the firewall status API)
set -u

ENGINE_DIR=${PA_ENGINE_DIR:-/opt/panelalpha/shared-hosting}

# FIREWALL_PROVIDER from the engine's .env, read as core reads it: Compose
# strips the quotes, then FirewallFactory::configuredName() trims and lowercases.
configured_provider() {
    local provider
    provider=$(sed -n 's/^FIREWALL_PROVIDER=//p' "$ENGINE_DIR/.env" 2>/dev/null | tail -n 1)
    provider=$(printf '%s' "$provider" | tr -d '\r' | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
        -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/" -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' | tr '[:upper:]' '[:lower:]')
    printf '%s' "${provider:-ufw}"
}

provider=$(configured_provider)
script="$(cd "$(dirname "$0")" && pwd)/firewall/$provider.sh"
if [ ! -f "$script" ]; then
    echo "firewall: no provider script for '$provider' ($script)" >&2
    exit 1
fi

case "${1:-}" in
--install | --apply | --fail2ban | --unhook | --uninstall | --refused) exec bash "$script" "${1#--}" ;;
*)
    echo "Usage: $0 --install|--apply|--fail2ban|--unhook|--uninstall|--refused" >&2
    exit 1
    ;;
esac
