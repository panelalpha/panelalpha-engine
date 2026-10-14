<?php

namespace App\Lib\Helpers;

class FileStreamWrapper
{
    /**
     * Set by PHP on every wrapper instance; undeclared it is a dynamic
     * property, deprecated since 8.2.
     *
     * @var resource|null
     */
    public $context;

    /**
     * The directory every stream opened through this wrapper must resolve
     * into, symlinks followed. Null means unconfined. Process-wide because
     * BinaryFileResponse opens the file itself, without a stream context.
     */
    private static ?string $root = null;

    /**
     * What the helper runs under. confineTo() sets the account's setpriv
     * prefix, so a read sees only what the account itself can.
     *
     * @var list<string>
     */
    private static array $runAs = ['sudo'];

    /**
     * @var resource|false|null $proc
     */
    private $proc = null;

    /**
     * @var array<resource> $pipes
     */
    private array $pipes = [];

    public static function register(): void
    {
        if (!in_array('sudophp', stream_get_wrappers())) {
            stream_wrapper_register('sudophp', __CLASS__);
        }
    }

    /**
     * Refuse any path whose real path (checked by the helper, so planted symlinks count) is not under
     * $root. $runAs is the helper's command prefix, required so reading as root is never a default.
     *
     * @param list<string> $runAs
     */
    public static function confineTo(?string $root, array $runAs): void
    {
        self::$root = $root === null ? null : rtrim($root, '/');
        self::$runAs = $runAs;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->proc = proc_open($this->helperArgv($mode, $path), $desc, $this->pipes);
        if (!is_resource($this->proc)) {
            return false;
        }

        if ($this->stream_stat() === false) {
            $this->abandonWithWarning();
            return false;
        }
        return true;
    }

    /** @return list<string> */
    private function helperArgv(string $mode, string $path): array
    {
        $cmd = [
            ...$this->helperCommand(),
            $mode,
            preg_replace('#^sudophp://#', '', $path),
        ];
        if (self::$root !== null) {
            $cmd[] = self::$root;
        }

        return $cmd;
    }

    private function abandonWithWarning(): void
    {
        $error = stream_get_contents($this->pipes[2]);
        $this->cleanup();
        trigger_error(
            'sudophp stream failed: ' . trim($error),
            E_USER_WARNING
        );
    }

    private function cleanup(): void
    {
        foreach ($this->pipes as $p) {
            @fclose($p);
        }
        if (is_resource($this->proc)) {
            @proc_close($this->proc);
        }
    }

    private function send(string $line): void
    {
        fwrite($this->pipes[0], $line . "\n");
        fflush($this->pipes[0]);
    }

    private function recv(): string
    {
        return rtrim(fgets($this->pipes[1]), "\r\n");
    }

    public function stream_read(int $count): string|bool
    {
        $this->send('READ ' . $count);
        $resp = $this->recv();
        if (strpos($resp, 'OK') !== 0) {
            return false;
        }

        return $this->readBytes(self::hexLengthAfterOk($resp));
    }

    private static function hexLengthAfterOk(string $resp): int|float
    {
        return hexdec(substr($resp, 3));
    }

    private function readBytes(int|float $length): string
    {
        $data = '';
        $remaining = $length;
        while ($remaining > 0 && ($chunk = fread($this->pipes[1], $remaining)) !== false) {
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    public function stream_write(string $data): int
    {
        $lenHex = dechex(strlen($data));
        $this->send('WRITE ' . $lenHex);
        fwrite($this->pipes[0], $data);
        fflush($this->pipes[0]);
        $resp = $this->recv();
        return (strpos($resp, 'OK') === 0) ? strlen($data) : 0;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $this->send('SEEK ' . $offset . ' ' . $whence);
        $resp = $this->recv();
        return (strpos($resp, 'OK') === 0);
    }

    public function stream_tell(): int
    {
        $this->send('TELL');
        $resp = $this->recv();
        if (strpos($resp, 'OK') === 0) {
            return (int)substr($resp, 3);
        }
        return 0;
    }

    public function stream_eof(): bool
    {
        $this->send('EOF');
        $resp = $this->recv();
        return (strpos($resp, 'OK 1') === 0);
    }

    /** Waits for the helper's acknowledgement before closing the pipes. */
    public function stream_close(): void
    {
        $this->send('CLOSE');
        $this->recv();
        $this->cleanup();
    }

    /**
     * Asked of the helper, as the account and confined like the read itself:
     * this user cannot see into a directory only the account can enter.
     */
    public function url_stat(string $path, int $flags): array|bool
    {
        $probe = new static();
        $opened = null;
        if (!@$probe->stream_open($path, 'r', 0, $opened)) {
            return false;
        }
        $stat = $probe->stream_stat();
        $probe->stream_close();
        if ($stat === false) {
            return false;
        }
        $stat[2] |= 0o666;
        $stat['mode'] |= 0o666;
        return $stat;
    }

    public function stream_stat(): array|bool
    {
        $this->send('STAT');
        $resp = $this->recv();

        if (strpos($resp, 'OK ') !== 0) {
            return false;
        }

        $json = substr($resp, 3);
        $stat = json_decode($json, true);
        if (!is_array($stat)) {
            return false;
        }
        return $stat;
    }

    public function stream_cast(int $cast_as): bool
    {
        return false;
    }

    /** @return list<string> */
    protected function helperCommand(): array
    {
        return [...self::$runAs, 'php', __DIR__ . '/file_stream.php'];
    }
}
