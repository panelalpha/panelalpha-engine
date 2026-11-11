<?php

namespace App\Lib\Project\PublicRouting;

/**
 * One host port an application asks for: `listen` on the server, forwarded to `to` in the account.
 */
final class PublicPort
{
    public const TCP = 'tcp';

    public const UDP = 'udp';

    public const TRANSPORTS = [self::TCP, self::UDP];

    public function __construct(
        public readonly int $listen,
        public readonly int $to,
        public readonly string $transport = self::TCP,
    ) {}

    /** The listener it takes on the host: one rule per transport and port. */
    public function key(): string
    {
        return $this->transport.'/'.$this->listen;
    }

    /** @return array{listen: int, to: int, transport: string} */
    public function toArray(): array
    {
        return ['listen' => $this->listen, 'to' => $this->to, 'transport' => $this->transport];
    }

    /** As {@see toArray()} wrote it, or null for anything else. */
    public static function fromArray(mixed $stored): ?self
    {
        if (! is_array($stored)) {
            return null;
        }
        $listen = $stored['listen'] ?? null;
        $to = $stored['to'] ?? null;
        $transport = $stored['transport'] ?? null;
        if (! is_int($listen) || ! is_int($to) || ! in_array($transport, self::TRANSPORTS, true)) {
            return null;
        }

        return new self($listen, $to, $transport);
    }
}
