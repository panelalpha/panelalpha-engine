<?php

namespace App\Lib\Findings;

use App\Exceptions\DeployBusyException;
use App\Exceptions\ProjectBusyException;
use App\Http\Controllers\User\ProjectSettingController;
use App\Http\Middleware\EnsureProjectNotProtected;
use App\Http\Middleware\EnsureTokenMayUseApi;
use App\Http\Middleware\McpAuthMiddleware;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Detect\DeployabilityCheck;
use App\Lib\Deploy\Inspect\AppInspector;
use App\Lib\Deploy\Inspect\Report\ApplicationReport;
use App\Lib\Deploy\Inspect\Report\DeployVerdict;
use App\Lib\Deploy\Inspect\Report\PortsReport;
use App\Lib\Deploy\Platform\UnknownRecipeException;
use App\Lib\Deploy\Platform\UnresolvedRuntimeException;
use App\Lib\Domains\HttpsPortsChange;
use App\Lib\Domains\PublicUrl;
use App\Lib\Project\CreateInspection;
use App\Lib\Project\PublicRouting\HostDomain;
use App\Lib\Task\LogStreamLimiter;
use App\Lib\Upload\ArchiveSources;
use App\Lib\Upload\Uploads;
use App\Lib\Vault\VaultDeployKeys;
use App\System\Project\Deployment\DeployMechanics;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\HostDiskLowException;
use ReflectionClass;

/** Every code a finding can carry, by what produced it. Says nothing about how a caller acts on one. */
final class FindingCodes
{
    /** A clone over SSH a project's own deploy key would make: the code is the field's name and what went wrong. */
    public const PROJECT_DEPLOY_KEY = [
        'repo_url_ssh_needs_deploy_key',
        'git_ssh_needs_deploy_key',
        'git_repo_ssh_unsupported',
        'repo_url_ssh_unsupported',
        'source.url_ssh_unsupported',
    ];

    public const PROJECT_DEPLOY_KEY_HOST = ['repo_url_ssh_host_not_pinned'];

    /** A create's clone over SSH, which only a deploy key kept in the vault can make. */
    public const VAULT_DEPLOY_KEY = [
        'source_ssh_needs_deploy_key',
        'source_auth_key_invalid',
        'git_repo_ssh_host_not_pinned',
        VaultDeployKeys::REFERENCE_INVALID,
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_unique([
            Finding::UNCLASSIFIED,
            ...self::warnings(),
            ...self::refusals(),
            ...self::failures(),
            ...self::ports(),
            ...self::problems(),
        ]));
    }

    /**
     * What a deploy that serves found wrong.
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        return [
            PublicUrl::UNRESOLVABLE,
            PublicUrl::CERTIFICATE_REFUSED,
            ...self::constantsOf(AppHealth::class, 'CODE_'),
            DeployMechanics::CUSTOM_ENV_SUSPECTED,
        ];
    }

    /**
     * Why detection or the deployability check would not deploy it, or a create's inspection says it may not.
     *
     * @return list<string>
     */
    public static function refusals(): array
    {
        return [
            ...self::constantsOf(DeployabilityCheck::class),
            ApplicationReport::DEPLOY_REFUSED,
            ApplicationReport::PYTHON_ENTRY_POINT_MISSING,
            AppInspector::DETECTION_FAILED,
            AppInspector::MANIFEST_INVALID,
            UnknownRecipeException::CODE,
            UnresolvedRuntimeException::CODE,
            CreateInspection::REPOSITORY_NOT_DEPLOYABLE,
            DeployVerdict::CODE_UNRECOGNISED,
            DeployVerdict::CODE_NOTHING_TO_START,
            DeployVerdict::CODE_NO_START_SCRIPT,
        ];
    }

    /**
     * Why a deploy failed, when it was not refused.
     *
     * @return list<string>
     */
    public static function failures(): array
    {
        return [...DeployFailureExplainer::failureCodes(), HostDiskLowException::CODE];
    }

    /**
     * @return list<string>
     */
    public static function ports(): array
    {
        return [PortsReport::SECONDARY_PORT, PortsReport::PORT_NOT_DETECTED];
    }

    /**
     * The codes of the refusals answered with `problems` that a hint acts on; the rest of `problems` has none.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        return [
            DeployBusyException::CODE,
            DeployBusyException::TASK,
            ProjectBusyException::TASK,
            LogStreamLimiter::CODE,
            EnsureTokenMayUseApi::NOT_FOR_API,
            McpAuthMiddleware::NOT_FOR_MCP,
            EnsureProjectNotProtected::CODE,
            ProjectSettingController::READ_ONLY,
            Uploads::NOT_FOUND,
            Uploads::EXPIRED,
            ArchiveSources::UPLOAD_GONE,
            HostDomain::CLAIM_REFUSED,
            HttpsPortsChange::NOT_ALLOWED,
            ...self::PROJECT_DEPLOY_KEY,
            ...self::PROJECT_DEPLOY_KEY_HOST,
            ...self::VAULT_DEPLOY_KEY,
        ];
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private static function constantsOf(string $class, string $prefix = ''): array
    {
        $constants = (new ReflectionClass($class))->getConstants();

        return array_values(array_filter(
            $constants,
            static fn (mixed $value, string $name): bool => is_string($value) && str_starts_with($name, $prefix),
            ARRAY_FILTER_USE_BOTH,
        ));
    }
}
