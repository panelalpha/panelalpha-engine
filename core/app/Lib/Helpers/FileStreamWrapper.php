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
     * Refuse any path that does not resolve to a file under $root. The check
     * runs in the privileged helper, on the real path, so a symlink planted
     * inside an account that points at /etc/shadow or another account's home
     * is turned away where it would otherwise be read as root.
     */
    public static function confineTo(?string $root): void
    {
        self::$root = $root === null ? null : rtrim($root, '/');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        $realPath = preg_replace('#^sudophp://#', '', $path);
        $cmd = [
            "sudo",
            "php",
            __DIR__ . '/file_stream.php',
            $mode,
            $realPath,
        ];
        if (self::$root !== null) {
            $cmd[] = self::$root;
        }
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $this->proc = proc_open($cmd, $desc, $this->pipes);
        if (!is_resource($this->proc)) {
            return false;
        }

        if ($this->stream_stat() === false) {
            $error = stream_get_contents($this->pipes[2]);
            $this->cleanup();
            trigger_error(
                'sudophp stream failed: ' . trim($error),
                E_USER_WARNING
            );
            return false;
        }
        return true;
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
        if (strpos($resp, 'OK') !== 0) return false;
        // length is hex after "OK "
        $hex = substr($resp, 3);
        $len = hexdec($hex);
        $data = '';
        $remaining = $len;
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

    public function stream_close(): void
    {
        $this->send('CLOSE');
        $this->recv(); // discard OK
        $this->cleanup();
    }

    public function url_stat(string $path, int $flags): array|bool
    {
        $realPath = preg_replace('#^sudophp://#', '', $path);
        if (self::$root !== null && !self::isUnder(self::$root, $realPath)) {
            return false;
        }
        $stat = @stat($realPath);
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

    /**
     * Whether $path, with every symlink followed, lies inside $root. This is
     * the unprivileged view; the helper repeats it as root, since a
     * root-only directory in the chain is unreadable from here.
     */
    public static function isUnder(string $root, string $path): bool
    {
        $realRoot = realpath($root);
        $real = realpath($path);
        if ($realRoot === false || $real === false) {
            return false;
        }

        return str_starts_with($real, rtrim($realRoot, '/') . '/');
    }
}
