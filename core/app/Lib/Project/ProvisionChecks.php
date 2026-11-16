<?php

namespace App\Lib\Project;

use App\Lib\Limits\ResourceLimit;

/**
 * Everything knowable about a create request before anything is bought, answered at once, not a round trip per mistake.
 * Run before DomainAllocator: allocation spends a panelalpha.online label from a fleet-wide namespace, never released.
 */
final class ProvisionChecks
{
    public function __construct(private readonly ProvisionEnvironment $env) {}

    /**
     * @param  array<string, mixed>  $params  validated create parameters
     * @param  string  $nameField  what the caller sent the name as
     * @return list<array<string, string>>
     */
    public function problems(array $params, string $nameField = 'username'): array
    {
        return array_values(array_filter([
            $this->username((string) ($params['username'] ?? ''), $nameField),
            $this->template($params),
            $this->diskSpace($params),
            $this->domain($params),
            $this->dedicatedIp($params, 4),
            $this->dedicatedIp($params, 6),
        ]));
    }

    /**
     * Availability on the host is asked here as well as after the account is built: a label bought for a project that is
     * then refused has to be given back.
     *
     * @return array<string, string>|null
     */
    private function username(string $username, string $nameField): ?array
    {
        if ($this->env->usernameRowExists($username)) {
            return [
                'field' => $nameField,
                'code' => 'name_taken',
                'message' => ProjectNameRefusal::taken($username),
            ];
        }

        if (! $this->env->usernameAvailableOnHost($username)) {
            return [
                'field' => $nameField,
                'code' => 'name_unavailable',
                'message' => ProjectNameRefusal::unavailable($username, $this->env->usernameHolders($username)),
            ];
        }

        return null;
    }

    /**
     * dind is what a git_repo deploys into anyway, so naming it is no conflict.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>|null
     */
    private function template(array $params): ?array
    {
        if (empty($params['template'])) {
            return null;
        }
        $template = (string) $params['template'];

        if ($template !== 'dind' && ! empty($params['git_repo'])) {
            return [
                'field' => 'template',
                'code' => 'template_conflicts_with_git',
                'message' => 'A git_repo deploys into the dind template; no other template can take one.',
            ];
        }

        if (! $this->env->templateExists($template)) {
            return [
                'field' => 'template',
                'code' => 'template_not_found',
                'message' => 'Template directory does not exist.',
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>|null
     */
    private function diskSpace(array $params): ?array
    {
        if (($params['disk_space_limit'] ?? ResourceLimit::UNLIMITED) >= ResourceLimit::UNLIMITED) {
            return null;
        }

        return [
            'field' => 'disk_space_limit',
            'code' => 'invalid_value',
            'message' => 'disk_space_limit must be an integer number of MB, or -1 for unlimited.',
            'expected' => 'an integer number of MB, -1 for unlimited',
        ];
    }

    /**
     * A domain the caller named, checked before the allocator is asked: it is the one candidate that never has an
     * alternative, so learning it is taken is worth doing before anything is bought.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>|null
     */
    private function domain(array $params): ?array
    {
        if (empty($params['domain'])) {
            return null;
        }
        $domain = (string) $params['domain'];
        if (! $this->env->domainOrAliasExists($domain)) {
            return null;
        }

        return [
            'field' => 'domain',
            'code' => 'domain_taken',
            'message' => "{$domain} is already on this engine.",
        ];
    }

    /**
     * A dedicated IP the host cannot give is refused here: the project would
     * otherwise be created on the shared IP and still report one.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>|null
     */
    private function dedicatedIp(array $params, int $family): ?array
    {
        $field = "dedicated_ipv{$family}";
        if (empty($params[$field]) || $this->env->freeDedicatedIpExists($family)) {
            return null;
        }

        return [
            'field' => $field,
            'code' => 'no_free_address',
            'message' => "No non-shared IPv{$family} subnet has a free address. Add one, or create the project without {$field}.",
        ];
    }
}
