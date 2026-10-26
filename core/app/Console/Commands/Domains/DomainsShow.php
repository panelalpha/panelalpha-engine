<?php

namespace App\Console\Commands\Domains;

use App\Models\Domain;
use App\Models\ProxyRule;
use App\Models\Tunnel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Helper\Table;

class DomainsShow extends Command
{
    /** The old spelling still answers, so nothing scripted against it breaks. */
    protected $aliases = ['domains:show', 'projects:domains:show'];

    protected $signature = 'domain:show {domain : Domain name or alias}';

    protected $description = 'Show how a domain is routed: proxy rules, tunnels, and/or document-root files';

    public function handle(): int
    {
        $name = strtolower(trim((string) $this->argument('domain')));
        if ($name === '') {
            $this->error('Domain name is required.');

            return 1;
        }

        $domain = Domain::findByNameOrAlias($name);
        if (! $domain) {
            $this->error("Domain '{$name}' not found.");

            return 1;
        }
        $domain->loadMissing('user');

        $user = $domain->user;
        $username = $user?->username ?? '-';
        $aliases = $domain->getAliases();
        $docRootRel = $domain->getDocumentRoot();
        $docRootAbs = $user
            ? rtrim($user->project()->homeDirPath(), '/').$docRootRel
            : $docRootRel;

        $serverNames = array_values(array_unique(array_filter(array_merge(
            [$domain->domain],
            $aliases
        ))));

        /** @var Collection<int, ProxyRule> $rules */
        $rules = ProxyRule::query()
            ->where('transport', 'http')
            ->whereIn('server_name', $serverNames)
            ->orderBy('listen_port')
            ->orderBy('id')
            ->get();

        $enabledRules = $rules->where('enabled', true)->values();
        $proxiesOnHttp = $enabledRules->contains(
            static fn (ProxyRule $r) => in_array((int) $r->listen_port, [80, 443], true)
        );

        $proxyPorts = $rules->map(static function (ProxyRule $rule): string {
            $line = "{$rule->listen_port} -> {$rule->upstream_host}:{$rule->upstream_port}";
            if (! $rule->enabled) {
                $line .= ' (disabled)';
            }

            return $line;
        })->all();

        $tunnels = Tunnel::forDomain($domain);
        $tunnelLines = array_map(
            static fn (Tunnel $t): string => "{$t->hostname} ({$t->provider})",
            $tunnels
        );

        $this->line('');
        $table = new Table($this->output);
        $table->setRows([
            ['Domain', $domain->domain],
            ['Project', $username],
            ['Type', $domain->type ?? '-'],
            ['Aliases', $aliases === [] ? '-' : implode("\n", $aliases)],
            ['Tunnels', $tunnelLines === [] ? '-' : implode("\n", $tunnelLines)],
            ['SSL', $domain->sslEnabled() ? 'enabled' : 'disabled'],
            ['Force HTTPS', $domain->forceHttpsRedirectEnabled() ? 'yes' : 'no'],
            ['Redirect', $domain->redirectEnabled() ? ((string) $domain->getRedirectUrl()) : '-'],
            ['Document root', $proxiesOnHttp ? '-' : $docRootRel],
            ['Document root (abs)', $proxiesOnHttp ? '-' : $docRootAbs],
            ['Proxy ports', $proxyPorts === [] ? '-' : implode("\n", $proxyPorts)],
        ]);
        $table->render();

        if ($tunnels !== []) {
            $this->comment(
                'Tunnels expose public hostnames via the provider (Cloudflare bypasses nginx). '
                .'ProxyRules remain the source of truth for the upstream port.'
            );
        } else {
            $this->comment(
                'Files from document root are served only when the domain does not proxy on ports 80/443.'
            );
        }
        $this->line('');

        return 0;
    }
}
