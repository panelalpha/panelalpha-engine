<?php

namespace App\Lib\Deploy\Telemetry;

/**
 * This server's own name and IPv4, masked in every string of a report: a project named under the name the engine's
 * certificate is issued for, or a line quoting the address, says which machine the report came from.
 */
final class ServerNames
{
    public const SERVER = '<server>';

    /**
     * @param  array<string, string>  $patterns  pattern => replacement, the address first
     */
    private function __construct(private readonly array $patterns) {}

    /**
     * Null when the server has neither on record. The address goes first, so a name that spells it, such as
     * `10-0-0-5.panelalpha.direct`, reads as the address zone it is.
     *
     * @param  array<mixed>  $names  the engine's certificate name and its IPv4
     */
    public static function for(array $names): ?self
    {
        $addresses = [];
        $hosts = [];
        foreach (array_filter($names, 'is_string') as $name) {
            $name = strtolower(trim($name, " \t\n\r\0\x0B."));
            if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $addresses += self::addressPatterns($name);
            } elseif ($name !== '' && TelemetryFields::normalizeHostname($name) === $name) {
                $hosts['#(?<![a-z0-9-])'.preg_quote($name, '#').'(?![a-z0-9-])#i'] = self::SERVER;
            }
        }

        $patterns = $addresses + $hosts;

        return $patterns === [] ? null : new self($patterns);
    }

    /**
     * Dotted and dashed, whatever the range: the server's own private address is still the server's.
     *
     * @return array<string, string>
     */
    private static function addressPatterns(string $ipv4): array
    {
        return [
            '#(?<!\d)(?<!\d\.)'.preg_quote($ipv4, '#').'(?!\d)(?!\.\d)#' => Redactor::ADDRESS_PLACEHOLDER,
            '#(?<!\d)(?<!\d-)'.preg_quote(str_replace('.', '-', $ipv4), '#').'(?!\d)(?!-\d)#' => Redactor::ADDRESS_PLACEHOLDER,
        ];
    }

    /**
     * Every string at any depth; keys and every other value are kept.
     *
     * @param  array<array-key, mixed>  $report
     * @return array<array-key, mixed>
     */
    public function scrub(array $report): array
    {
        return array_map(
            fn (mixed $value): mixed => match (true) {
                is_string($value) => $this->apply($value),
                is_array($value) => $this->scrub($value),
                default => $value,
            },
            $report
        );
    }

    public function apply(string $text): string
    {
        foreach ($this->patterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? Redactor::MASK;
        }

        return $text;
    }
}
