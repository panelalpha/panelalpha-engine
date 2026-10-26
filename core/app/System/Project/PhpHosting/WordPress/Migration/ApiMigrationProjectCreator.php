<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Http\Requests\UserStoreRequest;
use App\Lib\Domains\DomainPlan;
use Illuminate\Support\Facades\App;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates a classic project on the kept domain with no tunnel, or, when there
 * is no domain to keep, with tunnel=panelalpha so DomainAllocator picks the
 * online label. Deploy runs in-process so create_project finishes only after
 * the container is ready.
 */
final class ApiMigrationProjectCreator implements MigrationProjectCreator
{
    public function __construct(
        private ?ProjectDeployer $deployer = null,
    ) {
        $this->deployer ??= new ControllerProjectDeployer;
    }

    public function create(string $username, ?string $domain = null): string
    {
        $request = UserStoreRequest::create('/api/projects', 'POST', self::input($username, $domain));
        $request->setContainer(App::getInstance());
        $request->validateResolved();
        App::instance('request', $request);

        try {
            $user = $this->deployer->deploy($request);
        } catch (ValidationException $e) {
            throw new RuntimeException(self::validationMessage($e), 0, $e);
        }

        $domain = is_string($user->domain) ? $user->domain : '';
        if ($domain === '') {
            throw new RuntimeException(
                "Project '{$username}' was created but no domain was assigned."
            );
        }

        return $domain;
    }

    /**
     * @return array<string, string>
     */
    private static function input(string $username, ?string $domain): array
    {
        if ($domain === null) {
            return ['username' => $username, 'tunnel' => DomainPlan::TUNNEL_PANELALPHA];
        }

        return ['username' => $username, 'domain' => $domain, 'tunnel' => DomainPlan::TUNNEL_NONE];
    }

    private static function validationMessage(ValidationException $e): string
    {
        $messages = $e->errors();
        foreach ($messages as $fieldMessages) {
            foreach ($fieldMessages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        $fallback = $e->getMessage();

        return $fallback !== '' ? $fallback : 'Project creation failed validation.';
    }
}
