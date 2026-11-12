<?php

namespace App\System\Privileged;

/** Host command values. Like {@see Arguments}, they refuse only what a command could misread. */
final class HostValues
{
    private const INTERFACE = '/\A[A-Za-z0-9][A-Za-z0-9_.:@-]{0,31}\z/';

    private const PORTS = '/\A[0-9]+(?::[0-9]+)?(?:,[0-9]+(?::[0-9]+)?)*\z/';

    private const PROTOCOL = '/\A[a-z0-9]+\z/';

    private const COMMENT_REFUSED = '/[\'\r\n\x00]/';

    private const URL = '~\Ahttps?://[^\s\x00-\x1f\x7f]+\z~i';

    private const DEVICE = '~\A/dev/[A-Za-z0-9][A-Za-z0-9_.:/-]*\z~';

    private const UFW_COMMANDS = [['--force', 'enable'], ['reload'], ['disable']];

    private const UFW_ACTIONS = ['allow', 'deny', 'reject', 'limit'];

    private const TRUSTED_LINE = '/\A(\S+)(?: # [^\r\n\x00]*)?\z/';

    public static function family(int $family): string
    {
        if ($family !== 4 && $family !== 6) {
            throw InvalidArgument::of('IP family', (string) $family);
        }

        return (string) $family;
    }

    public static function address(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw InvalidArgument::of('IP address', $address);
        }

        return $address;
    }

    public static function ipv4(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw InvalidArgument::of('IPv4 address', $address);
        }

        return $address;
    }

    /** `address/mask`, the mask within the address's own family. */
    public static function network(string $address, int $mask): string
    {
        $bits = str_contains(self::address($address), ':') ? 128 : 32;
        if ($mask < 0 || $mask > $bits) {
            throw InvalidArgument::of('network mask', (string) $mask);
        }

        return $address.'/'.$mask;
    }

    /** An address, or a range of them, as a firewall rule or a ban names one. */
    public static function addressOrNetwork(string $value): string
    {
        [$address, $mask] = array_pad(explode('/', $value, 2), 2, null);
        if ($mask === null) {
            return self::address($value);
        }
        if (preg_match('/\A[0-9]{1,3}\z/', $mask) !== 1) {
            throw InvalidArgument::of('network', $value);
        }
        self::network($address, (int) $mask);

        return $value;
    }

    public static function interface(string $name): string
    {
        return self::matching(self::INTERFACE, 'network interface', $name);
    }

    public static function url(string $url): string
    {
        return self::matching(self::URL, 'URL', $url);
    }

    public static function device(string $device): string
    {
        if (preg_match(self::DEVICE, $device) !== 1 || str_contains($device, '/../')) {
            throw InvalidArgument::of('block device', $device);
        }

        return $device;
    }

    public static function positive(int $number, string $kind): string
    {
        if ($number < 1) {
            throw InvalidArgument::of($kind, (string) $number);
        }

        return (string) $number;
    }

    public static function count(int $number, string $kind): string
    {
        if ($number < 0) {
            throw InvalidArgument::of($kind, (string) $number);
        }

        return (string) $number;
    }

    /** A CPU count as `docker update --cpus` takes it: a plain decimal above zero, since Docker reads 0 as no change. */
    public static function cpus(int|float $cpus): string
    {
        $decimal = is_finite((float) $cpus) && $cpus > 0 ? rtrim(rtrim(sprintf('%.6F', (float) $cpus), '0'), '.') : '0';
        if ($decimal === '0') {
            throw InvalidArgument::of('CPU limit', (string) $cpus);
        }

        return $decimal;
    }

    /**
     * `--force enable`, `reload`, `disable`, or one rule
     * `[route] [prepend|delete] ACTION [in|out] [proto P] from A to B [port P] [comment C]`.
     *
     * @param  array<int, string>  $words
     * @return list<string>
     */
    public static function ufwWords(array $words): array
    {
        $words = array_values($words);
        if (in_array($words, self::UFW_COMMANDS, true) || self::isUfwRule($words)) {
            return $words;
        }

        throw InvalidArgument::of('ufw command', implode(' ', $words));
    }

    /** One `address # comment` line per entry, as UfwFirewall writes it. */
    public static function trustedList(string $list): string
    {
        $lines = $list === '' ? [] : explode("\n", rtrim($list, "\n"));
        if ($list !== '' && ! str_ends_with($list, "\n")) {
            throw InvalidArgument::of('trusted list', $list);
        }
        foreach ($lines as $line) {
            if (preg_match(self::TRUSTED_LINE, $line, $match) !== 1) {
                throw InvalidArgument::of('trusted list line', $line);
            }
            self::addressOrNetwork($match[1]);
        }

        return $list;
    }

    /** @param  list<string>  $words */
    private static function isUfwRule(array $words): bool
    {
        $at = 0;
        $at += ($words[$at] ?? null) === 'route' ? 1 : 0;
        $at += in_array($words[$at] ?? null, ['prepend', 'delete'], true) ? 1 : 0;
        if (! in_array($words[$at++] ?? null, self::UFW_ACTIONS, true)) {
            return false;
        }
        $at += in_array($words[$at] ?? null, ['in', 'out'], true) ? 1 : 0;
        $checks = [
            'proto' => fn (string $v): bool => preg_match(self::PROTOCOL, $v) === 1,
            'from' => self::isRuleAddress(...),
            'to' => self::isRuleAddress(...),
            'port' => fn (string $v): bool => preg_match(self::PORTS, $v) === 1,
            'comment' => fn (string $v): bool => preg_match(self::COMMENT_REFUSED, $v) !== 1,
        ];
        foreach ($checks as $keyword => $check) {
            $required = $keyword === 'from' || $keyword === 'to';
            if (($words[$at] ?? null) !== $keyword) {
                if ($required) {
                    return false;
                }

                continue;
            }
            if (! isset($words[$at + 1]) || ! $check($words[$at + 1])) {
                return false;
            }
            $at += 2;
        }

        return $at === count($words);
    }

    private static function isRuleAddress(string $value): bool
    {
        if ($value === 'any') {
            return true;
        }
        try {
            self::addressOrNetwork($value);

            return true;
        } catch (InvalidArgument) {
            return false;
        }
    }

    private static function matching(string $pattern, string $kind, string $value): string
    {
        if (preg_match($pattern, $value) !== 1) {
            throw InvalidArgument::of($kind, $value);
        }

        return $value;
    }
}
