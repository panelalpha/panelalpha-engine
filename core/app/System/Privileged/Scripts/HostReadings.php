<?php

namespace App\System\Privileged\Scripts;

use App\System\Project\Dind\LxcfsProc;

/** Read-only shell the engine runs without root. The root broker keeps its own copies for the host. */
final class HostReadings
{
    /** Fetches through the webserver without DNS, over https when the domain has TLS. */
    public function edgeProbe(string $domain, string $ip, int $timeout, bool $https): string
    {
        $timeout = max(1, $timeout);
        $domain = escapeshellarg($domain);
        $ip = escapeshellarg($ip);
        $secure = $https ? 1 : 0;

        return <<<SH
set -u
host={$domain}
addr={$ip}
f=\$(mktemp)
scheme=http
out=000
if [ {$secure} = 1 ]; then
  out=\$(curl -sS -k -m {$timeout} -o "\$f" -w '%{http_code} %{redirect_url}' --resolve "\$host:443:\$addr" "https://\$host/" 2>/dev/null) || out=000
  [ "\${out%% *}" = 000 ] || scheme=https
fi
if [ "\$scheme" = http ]; then
  out=\$(curl -sS -m {$timeout} -o "\$f" -w '%{http_code} %{redirect_url}' -H "Host: \$host" "http://\$addr/" 2>/dev/null) || out=000
fi
code=\${out%% *}
location=\$(printf '%s' "\${out#* }" | tr -d '\t\r\n')
[ "\$location" = "\$out" ] && location=
marker=no
if [ "\$code" = "404" ] && grep -q 'Page Not Found' "\$f" && grep -q 'error-page' "\$f"; then marker=yes; fi
printf '%s\t%s\t%s\t\t%s\t%s' "\${code:-000}" "\$(head -c 2048 "\$f" | sha256sum | cut -d' ' -f1)" "\$marker" "\$location" "\$scheme"
rm -f "\$f"
exit 0
SH;
    }

    /** Which of {@see LxcfsProc::FILES} lxcfs serves, one per line. */
    public function lxcfsFiles(): string
    {
        return 'for f in '.implode(' ', LxcfsProc::FILES).'; do [ -f '.LxcfsProc::PROC_DIR.'/$f ] && echo "$f"; done; true';
    }
}
