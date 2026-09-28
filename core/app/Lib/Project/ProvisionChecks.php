<?php

namespace App\Lib\Project;

/**
 * Everything knowable about a create request before anything is bought, asked
 * at once and answered at once.
 *
 * These used to be sequential throws, so a caller with a taken name *and* a bad
 * template learned about the second only after fixing the first -- a round trip
 * per mistake, for mistakes the server could see together. They run before
 * DomainAllocator, because allocation spends a panelalpha.online label out of a
 * namespace the whole fleet shares and a spent label is never released.
 */
final class ProvisionChecks
{
    public function __construct(private readonly ProvisionEnvironment $env)
    {
    }

    /**
     * @param array<string, mixed> $params     validated create parameters
     * @param string               $nameField  what the caller sent the name as
     *
     * @return list<array<string, string>>
     */
    public function problems(array $params, string $nameField = 'username'): array
    {
        return array_values(array_filter([
            $this->username((string) ($params['username'] ?? ''), $nameField),
            $this->template($params),
            $this->diskSpace($params),
            $this->domain($params),
        ]));
    }

    /** @return array<string, string>|null */
    private function username(string $username, string $nameField): ?array
    {
        if ($this->env->usernameRowExists($username)) {
            return [
                'field' => $nameField,
                'code' => 'name_taken',
                'message' => "A project named '{$username}' already exists. Choose another name.",
            ];
        }

        // Asked here as well as after the account is built, because allocation
        // spends a label out of a namespace the whole fleet shares. A name
        // burnt on a project that was never going to be created is gone for
        // good -- PanelAlpha Online has no release.
        if (!$this->env->usernameAvailableOnHost($username)) {
            return [
                'field' => $nameField,
                'code' => 'name_unavailable',
                'message' => "'{$username}' is reserved or already used by the system. Choose another name.",
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string>|null
     */
    private function template(array $params): ?array
    {
        if (empty($params['template'])) {
            return null;
        }
        $template = (string) $params['template'];

        // dind is what a git_repo deploys into anyway, so naming it is no conflict.
        if ($template !== 'dind' && !empty($params['git_repo'])) {
            return [
                'field' => 'template',
                'code' => 'template_conflicts_with_git',
                'message' => 'A git_repo deploys into the dind template; no other template can take one.',
            ];
        }

        if (!$this->env->templateExists($template)) {
            return [
                'field' => 'template',
                'code' => 'template_not_found',
                'message' => 'Template directory does not exist.',
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string>|null
     */
    private function diskSpace(array $params): ?array
    {
        if (($params['disk_space_limit'] ?? -1) >= -1) {
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
     * A domain the caller named, checked before the allocator is asked -- it is
     * the one candidate that never has an alternative, so learning it is taken
     * is worth doing before anything is bought.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, string>|null
     */
    private function domain(array $params): ?array
    {
        if (empty($params['domain'])) {
            return null;
        }
        $domain = (string) $params['domain'];
        if (!$this->env->domainOrAliasExists($domain)) {
            return null;
        }

        return [
            'field' => 'domain',
            'code' => 'domain_taken',
            'message' => "{$domain} is already on this engine.",
        ];
    }
}
