<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\FirewallRule;

/**
 * ufw's rule store to {@see FirewallRule} and back.
 *
 * ufw keeps one `### tuple ###` line per rule in /etc/ufw/user.rules and
 * user6.rules: `action proto dport dst sport src [dapp sapp] direction[_iface]
 * [comment=<hex>]`. A rule added for any address is written to both files,
 * once with 0.0.0.0/0 and once with ::/0; it is one rule here. A route rule
 * (`ufw route ...`, ufw-user-forward) has its action as route:<action>; it is
 * a rule for published ports.
 */
final class UfwRules
{
    private const ANY_ADDRESS = ['0.0.0.0/0', '::/0'];

    /** @return list<FirewallRule> */
    public static function parse(string $v4, string $v6 = ''): array
    {
        $rules = [];
        foreach (preg_split('/\R/', $v4 . "\n" . $v6) ?: [] as $line) {
            $rule = self::parseTuple($line);
            if ($rule !== null) {
                $rules[$rule->id()] ??= $rule;
            }
        }

        return self::pairScopes(self::pairBothDirections(array_values($rules)));
    }

    /**
     * A host deny and a route deny, otherwise alike, are one deny in `both`
     * scopes, as the API and fail2ban write it; listed once, where the host
     * half is.
     *
     * @param list<FirewallRule> $rules
     * @return list<FirewallRule>
     */
    private static function pairScopes(array $rules): array
    {
        $key = static fn (FirewallRule $r): string => (string) json_encode([$r->protocol, $r->port, $r->source, $r->destination, $r->comment]);
        $routed = [];
        foreach ($rules as $i => $rule) {
            if ($rule->editable && $rule->scope === FirewallRule::PUBLISHED && $rule->action === FirewallRule::DENY) {
                $routed[$key($rule)] ??= $i;
            }
        }

        // Either half can come first; the rule is listed where the host half is.
        $both = [];
        $taken = [];
        foreach ($rules as $i => $rule) {
            if (!$rule->editable || $rule->scope !== FirewallRule::HOST || $rule->action !== FirewallRule::DENY || $rule->direction === FirewallRule::OUT) {
                continue;
            }
            // A deny both ways has its route half on the inbound side only.
            $inbound = $rule->direction === FirewallRule::BOTH ? $rule->with(['direction' => FirewallRule::IN]) : $rule;
            if (isset($routed[$k = $key($inbound)])) {
                $both[$i] = true;
                $taken[$routed[$k]] = true;
                unset($routed[$k]);
            }
        }

        $paired = [];
        foreach ($rules as $i => $rule) {
            if (isset($taken[$i])) {
                continue;
            }
            $paired[] = isset($both[$i])
                ? new FirewallRule($rule->action, $rule->direction, $rule->protocol, $rule->port, $rule->source, $rule->destination, $rule->comment, scope: FirewallRule::BOTH)
                : $rule;
        }

        return $paired;
    }

    /**
     * An inbound rule from an address and an outbound rule to it, otherwise
     * alike, are the two halves of one `both` rule; listed once, where the
     * first half is.
     *
     * @param list<FirewallRule> $rules
     * @return list<FirewallRule>
     */
    private static function pairBothDirections(array $rules): array
    {
        $outbound = [];
        foreach ($rules as $i => $rule) {
            if ($rule->editable && $rule->direction === FirewallRule::OUT && $rule->source === null && $rule->destination !== null) {
                $outbound[self::halfKey($rule, $rule->destination)] = $i;
            }
        }
        // Either half can come first: a deny is prepended, so its outbound half,
        // written second, ends up above the inbound one.
        $partner = [];
        foreach ($rules as $i => $rule) {
            if ($rule->editable && $rule->direction === FirewallRule::IN && $rule->source !== null && $rule->destination === null
                && isset($outbound[$key = self::halfKey($rule, $rule->source)]) && !isset($partner[$outbound[$key]])) {
                $partner[$i] = $outbound[$key];
                $partner[$outbound[$key]] = $i;
            }
        }

        $paired = [];
        $done = [];
        foreach ($rules as $i => $rule) {
            if (isset($done[$i])) {
                continue;
            }
            if (!isset($partner[$i])) {
                $paired[] = $rule;
                continue;
            }
            $in = $rule->direction === FirewallRule::IN ? $rule : $rules[$partner[$i]];
            $done[$partner[$i]] = true;
            $paired[] = new FirewallRule($in->action, FirewallRule::BOTH, $in->protocol, $in->port, $in->source, null, $in->comment);
        }

        return $paired;
    }

    private static function halfKey(FirewallRule $rule, string $address): string
    {
        return (string) json_encode([$rule->scope, $rule->action, $rule->protocol, $rule->port, $address, $rule->comment]);
    }

    public static function parseTuple(string $line): ?FirewallRule
    {
        if (!str_starts_with($line, '### tuple ###')) {
            return null;
        }
        $tuple = trim(substr($line, strlen('### tuple ###')));
        $comment = null;
        if (str_contains($tuple, ' comment=')) {
            [$tuple, $hex] = explode(' comment=', $tuple, 2);
            $decoded = ctype_xdigit(trim($hex)) ? hex2bin(trim($hex)) : false;
            $comment = $decoded === false ? null : $decoded;
        }

        $f = preg_split('/\s+/', trim($tuple)) ?: [];
        if (count($f) !== 7 && count($f) !== 9) {
            return null;
        }
        [$action, $proto, $dport, $dst, $sport, $src] = $f;
        $direction = $f[count($f) - 1];
        $any = static fn (string $v): ?string => in_array($v, ['any', ...self::ANY_ADDRESS], true) ? null : $v;
        $scope = FirewallRule::HOST;
        if (str_starts_with($action, 'route:')) {
            $scope = FirewallRule::PUBLISHED;
            $action = substr($action, strlen('route:'));
        }

        // A route rule names a direction only with an interface, which the model does not carry.
        $editable = count($f) === 7
            && in_array($action, [FirewallRule::ALLOW, FirewallRule::DENY], true)
            && in_array($direction, $scope === FirewallRule::HOST ? [FirewallRule::IN, FirewallRule::OUT] : [FirewallRule::IN], true)
            && $sport === 'any';
        $raw = str_replace(self::ANY_ADDRESS, 'any', $tuple);

        return new FirewallRule(
            action: $action,
            direction: explode('_', $direction, 2)[0],
            protocol: $any($proto),
            port: $any($dport),
            source: $any($src),
            destination: $any($dst),
            comment: $comment,
            editable: $editable,
            raw: $editable ? null : $raw,
            scope: $scope,
        );
    }

    /**
     * The words after `ufw` (or `ufw delete`) for each ufw rule this one is:
     * one, or two for a rule in both directions, and the route rule of a
     * deny in both scopes.
     *
     * @return list<list<string>>
     */
    public static function specs(FirewallRule $rule, bool $withComment = true): array
    {
        $host = $rule->scope === FirewallRule::BOTH ? FirewallRule::HOST : $rule->scope;
        $half = static fn (string $direction, ?string $source, ?string $destination, string $scope): FirewallRule
            => new FirewallRule($rule->action, $direction, $rule->protocol, $rule->port, $source, $destination, $rule->comment, scope: $scope);

        $halves = $rule->direction === FirewallRule::BOTH
            ? [$half(FirewallRule::IN, $rule->source, null, $host), $half(FirewallRule::OUT, null, $rule->source, $host)]
            : [$half($rule->direction, $rule->source, $rule->destination, $host)];
        if ($rule->scope === FirewallRule::BOTH) {
            $halves[] = $half(FirewallRule::IN, $rule->source, $rule->direction === FirewallRule::BOTH ? null : $rule->destination, FirewallRule::PUBLISHED);
        }

        return array_map(static fn (FirewallRule $r): array => self::spec($r, $withComment), $halves);
    }

    /**
     * The words after `ufw` (or `ufw delete`) that name one ufw rule; a rule
     * for published ports starts with `route`, and the verb goes after it.
     *
     * @return list<string>
     */
    public static function spec(FirewallRule $rule, bool $withComment = true): array
    {
        $spec = $rule->scope === FirewallRule::PUBLISHED ? ['route', $rule->action] : [$rule->action, $rule->direction];
        if ($rule->protocol !== null) {
            array_push($spec, 'proto', $rule->protocol);
        }
        array_push($spec, 'from', $rule->source ?? 'any', 'to', $rule->destination ?? 'any');
        if ($rule->port !== null) {
            array_push($spec, 'port', $rule->port);
        }
        if ($withComment && $rule->comment !== null) {
            array_push($spec, 'comment', $rule->comment);
        }

        return $spec;
    }
}
