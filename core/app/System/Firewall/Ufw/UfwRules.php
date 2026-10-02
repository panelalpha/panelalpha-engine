<?php

namespace App\System\Firewall\Ufw;

use App\System\Firewall\FirewallRule;

/**
 * ufw's rule store to {@see FirewallRule} and back.
 *
 * ufw keeps one `### tuple ###` line per rule in /etc/ufw/user.rules and
 * user6.rules: `action proto dport dst sport src [dapp sapp] direction[_iface]
 * [comment=<hex>]`. A rule added for any address is written to both files,
 * once with 0.0.0.0/0 and once with ::/0; it is one rule here.
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

        return self::pairBothDirections(array_values($rules));
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
        return (string) json_encode([$rule->action, $rule->protocol, $rule->port, $address, $rule->comment]);
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

        $editable = count($f) === 7
            && in_array($action, [FirewallRule::ALLOW, FirewallRule::DENY], true)
            && in_array($direction, [FirewallRule::IN, FirewallRule::OUT], true)
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
        );
    }

    /**
     * The words after `ufw` (or `ufw delete`) for each ufw rule this one is:
     * one, or two for a rule in both directions.
     *
     * @return list<list<string>>
     */
    public static function specs(FirewallRule $rule, bool $withComment = true): array
    {
        if ($rule->direction !== FirewallRule::BOTH) {
            return [self::spec($rule, $withComment)];
        }

        return [
            self::spec(new FirewallRule($rule->action, FirewallRule::IN, $rule->protocol, $rule->port, $rule->source, null, $rule->comment), $withComment),
            self::spec(new FirewallRule($rule->action, FirewallRule::OUT, $rule->protocol, $rule->port, null, $rule->source, $rule->comment), $withComment),
        ];
    }

    /**
     * The words after `ufw` (or `ufw delete`) that name one ufw rule.
     *
     * @return list<string>
     */
    public static function spec(FirewallRule $rule, bool $withComment = true): array
    {
        $spec = [$rule->action, $rule->direction];
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
