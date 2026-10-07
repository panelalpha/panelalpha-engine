<?php

namespace App\System\Services\Webserver;

use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallClash;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallRule;
use Illuminate\Support\Facades\Log;

/**
 * Host firewall allows for the ports the custom proxy rules listen on.
 *
 * sites-http runs on the host's network, so a tcp or udp rule's port, and an
 * http rule's port other than 80 and 443, is a host port, closed by the
 * default incoming policy until something allows it. Each allow carries the
 * managed prefix, so the firewall API lists it as the engine's, and only
 * allows carrying it are ever removed here: an operator's own rule for the
 * same port is left alone.
 *
 * @psalm-type PortRule = array{id: int, transport: string, listen_ip: string, listen_port: int, is_generated: bool}
 */
class ProxyRulePorts
{
    public const COMMENT = FirewallRule::MANAGED_PREFIX . ' proxy rule ';

    public function __construct(private ?Firewall $firewall = null)
    {
    }

    /**
     * Allow every custom rule's port, and drop the allows of rules that are
     * gone or disabled. A port another rule already holds, such as an
     * operator's deny, is left as that rule has it while the others open;
     * every sync tries it again while its proxy rule exists. A firewall that
     * cannot be read or changed never fails the caller; false tells it to
     * try again next time.
     *
     * @psalm-param list<PortRule> $rules
     */
    public function sync(array $rules): bool
    {
        try {
            $firewall = $this->firewall ?? FirewallFactory::default();
            $wanted = self::allows($rules);

            $present = [];
            foreach ($firewall->rules() as $rule) {
                $present[$rule->id()] = true;
                if (str_starts_with((string) $rule->comment, self::COMMENT) && !isset($wanted[$rule->id()])) {
                    $firewall->deleteRule($rule->id());
                }
            }
            foreach ($wanted as $id => $rule) {
                if (isset($present[$id])) {
                    continue;
                }
                try {
                    $firewall->addRule($rule);
                } catch (FirewallClash $e) {
                    // Still synced: the caller's marker has to hold the ports opened beside it, so they close later.
                    Log::warning('Could not update the firewall for the proxy rules: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not update the firewall for the proxy rules: ' . $e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * @psalm-param list<PortRule> $rules
     * @return array<string, FirewallRule> by rule id
     */
    public static function allows(array $rules): array
    {
        $allows = [];
        foreach ($rules as $rule) {
            if ($rule['is_generated'] !== false) {
                continue;
            }
            // The webserver's own ports are open already.
            if ($rule['transport'] === 'http' && in_array($rule['listen_port'], ProxyListenPort::WEBSERVER, true)) {
                continue;
            }
            $allow = new FirewallRule(
                action: FirewallRule::ALLOW,
                direction: FirewallRule::IN,
                protocol: $rule['transport'] === 'udp' ? 'udp' : 'tcp',
                port: (string) $rule['listen_port'],
                destination: $rule['listen_ip'] === '*' ? null : FirewallRule::normalizeAddress($rule['listen_ip']),
                comment: self::COMMENT . $rule['id'],
            );
            $allows[$allow->id()] ??= $allow;
        }

        return $allows;
    }
}
