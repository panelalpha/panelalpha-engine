<?php

namespace App\System\Services\Webserver;

use App\System\Firewall\Firewall;
use App\System\Firewall\FirewallClash;
use App\System\Firewall\FirewallFactory;
use App\System\Firewall\FirewallRule;
use Illuminate\Support\Facades\Log;

/**
 * Host firewall allows for the host ports custom proxy rules listen on, each carrying the managed prefix:
 * only allows carrying it are ever removed, so an operator's own rule for the same port is left alone.
 *
 * @psalm-type PortRule = array{id: int|string, transport: string, listen_ip: string, listen_port: int, is_generated: bool}
 */
class ProxyRulePorts
{
    public const COMMENT = FirewallRule::MANAGED_PREFIX.' proxy rule ';

    public function __construct(private ?Firewall $firewall = null) {}

    /**
     * Allow every custom rule's port and drop the allows of rules gone or disabled; false asks to try again. A port
     * another rule holds stays as it is and the sync still succeeds, so the ports opened beside it close later.
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
                if (str_starts_with((string) $rule->comment, self::COMMENT) && ! isset($wanted[$rule->id()])) {
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
                    Log::warning('Could not update the firewall for the proxy rules: '.$e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Could not update the firewall for the proxy rules: '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Custom rules only, and none for an http rule on the webserver's own ports, which are open already.
     *
     * @psalm-param list<PortRule> $rules
     *
     * @return array<string, FirewallRule> by rule id
     */
    public static function allows(array $rules): array
    {
        $allows = [];
        foreach ($rules as $rule) {
            if ($rule['is_generated'] !== false) {
                continue;
            }
            if ($rule['transport'] === 'http' && in_array($rule['listen_port'], ProxyListenPort::WEBSERVER, true)) {
                continue;
            }
            $allow = new FirewallRule(
                action: FirewallRule::ALLOW,
                direction: FirewallRule::IN,
                protocol: $rule['transport'] === 'udp' ? 'udp' : 'tcp',
                port: (string) $rule['listen_port'],
                destination: $rule['listen_ip'] === '*' ? null : FirewallRule::normalizeAddress($rule['listen_ip']),
                comment: self::COMMENT.$rule['id'],
            );
            $allows[$allow->id()] ??= $allow;
        }

        return $allows;
    }
}
