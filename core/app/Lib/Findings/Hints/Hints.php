<?php

namespace App\Lib\Findings\Hints;

use App\Lib\Findings\Hints\Actions\AddDomain;
use App\Lib\Findings\Hints\Actions\AllowHostDomain;
use App\Lib\Findings\Hints\Actions\AllowHttpsPorts;
use App\Lib\Findings\Hints\Actions\CancelTask;
use App\Lib\Findings\Hints\Actions\CheckAppHealth;
use App\Lib\Findings\Hints\Actions\CheckCertificate;
use App\Lib\Findings\Hints\Actions\ConnectRepository;
use App\Lib\Findings\Hints\Actions\CreateDeployKey;
use App\Lib\Findings\Hints\Actions\CreateVaultDeployKey;
use App\Lib\Findings\Hints\Actions\FollowTask;
use App\Lib\Findings\Hints\Actions\InspectWithRecipe;
use App\Lib\Findings\Hints\Actions\ListEnvVars;
use App\Lib\Findings\Hints\Actions\LogInToDockerHub;
use App\Lib\Findings\Hints\Actions\MintApiToken;
use App\Lib\Findings\Hints\Actions\MintMcpToken;
use App\Lib\Findings\Hints\Actions\PinDeployKeyHost;
use App\Lib\Findings\Hints\Actions\PrewarmImages;
use App\Lib\Findings\Hints\Actions\ProtectProject;
use App\Lib\Findings\Hints\Actions\PruneHostImages;
use App\Lib\Findings\Hints\Actions\RaiseDiskLimit;
use App\Lib\Findings\Hints\Actions\RaiseMemoryLimit;
use App\Lib\Findings\Hints\Actions\ReadAppLogs;
use App\Lib\Findings\Hints\Actions\ReadDeployLog;
use App\Lib\Findings\Hints\Actions\ReadTaskLogs;
use App\Lib\Findings\Hints\Actions\RecreateProjectContainer;
use App\Lib\Findings\Hints\Actions\Redeploy;
use App\Lib\Findings\Hints\Actions\RedeployWithRecipe;
use App\Lib\Findings\Hints\Actions\RequestCertificate;
use App\Lib\Findings\Hints\Actions\RouteDomainToPort;
use App\Lib\Findings\Hints\Actions\RoutePortHttp;
use App\Lib\Findings\Hints\Actions\RoutePortTcp;
use App\Lib\Findings\Hints\Actions\SetEnvVars;
use App\Lib\Findings\Hints\Actions\SetRegistryAuth;
use App\Lib\Findings\Hints\Actions\UnprotectProject;
use App\Lib\Findings\Hints\Actions\UpdateGitCredentials;
use App\Lib\Findings\Hints\Actions\UploadArchive;

/**
 * Every hint the engine offers, in the order an answer lists them: what fixes the finding, then what tells the caller
 * more, then deploying again. The only place the hints are listed; each says itself which codes it acts on.
 */
final class Hints
{
    /** @var list<class-string<Hint>> */
    public const ALL = [
        RequestCertificate::class,
        CheckCertificate::class,
        UnprotectProject::class,
        ProtectProject::class,
        AllowHostDomain::class,
        AllowHttpsPorts::class,
        MintApiToken::class,
        MintMcpToken::class,
        CreateDeployKey::class,
        PinDeployKeyHost::class,
        CreateVaultDeployKey::class,
        ConnectRepository::class,
        UploadArchive::class,
        FollowTask::class,
        CancelTask::class,
        ReadTaskLogs::class,
        AddDomain::class,
        RouteDomainToPort::class,
        RoutePortHttp::class,
        RoutePortTcp::class,
        RaiseMemoryLimit::class,
        RaiseDiskLimit::class,
        PruneHostImages::class,
        RecreateProjectContainer::class,
        SetRegistryAuth::class,
        LogInToDockerHub::class,
        PrewarmImages::class,
        UpdateGitCredentials::class,
        ListEnvVars::class,
        SetEnvVars::class,
        InspectWithRecipe::class,
        RedeployWithRecipe::class,
        ReadAppLogs::class,
        CheckAppHealth::class,
        ReadDeployLog::class,
        Redeploy::class,
    ];

    /** @var array<string, list<Hint>>|null */
    private static ?array $byCode = null;

    /**
     * @return list<Hint>
     */
    public static function for(string $code): array
    {
        return self::byCode()[$code] ?? [];
    }

    /**
     * @return array<string, list<Hint>>
     */
    private static function byCode(): array
    {
        if (self::$byCode === null) {
            self::$byCode = [];
            foreach (self::ALL as $class) {
                $hint = new $class;
                foreach ($hint->resolves() as $code) {
                    self::$byCode[$code][] = $hint;
                }
            }
        }

        return self::$byCode;
    }
}
