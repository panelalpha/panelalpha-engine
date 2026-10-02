<?php

namespace Tests\Unit;

use App\Http\Controllers\UserController;
use App\Lib\Domains\DomainPlan;
use App\Lib\Ssl\CertificateStatus;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A rebuild's deploy log ends `partial` with the public-URL warnings (a name
 * that resolves privately, a self-signed certificate). The project record has
 * to say the same, or GET /projects reports `success` for that rebuild.
 */
class RebuildRecordsPublicUrlWarningsTest extends TestCase
{
    private function user(array $details): User
    {
        $user = new class extends User {
            public function save(array $options = []): bool
            {
                return true;
            }
        };
        $user->domain = 'fz2.10-10-0-25.panelalpha.direct';
        $user->setDetails($details);

        return $user;
    }

    private function record(User $user): void
    {
        (new ReflectionMethod(UserController::class, 'recordRebuildSucceeded'))->invoke(new UserController(), $user);
    }

    /** @return array<string, mixed> */
    private function privateNameDetails(): array
    {
        return [
            'domain' => [
                'source' => DomainPlan::SOURCE_PANELALPHA_DIRECT,
                'publicly_resolvable' => false,
                'tls_terminated_at' => 'engine',
                'tunnel' => null,
                'fallback_reason' => 'no public IPv4',
            ],
            'ssl' => [
                'domain' => 'fz2.10-10-0-25.panelalpha.direct',
                'status' => CertificateStatus::SELF_SIGNED,
                'issuer' => 'PanelAlpha',
                'self_signed' => true,
            ],
        ];
    }

    public function test_a_rebuild_behind_a_private_name_stays_partial(): void
    {
        $user = $this->user(array_merge($this->privateNameDetails(), [
            'deployment_status' => 'partial',
            'deployment_warnings' => ['left by the first deploy'],
        ]));

        $this->record($user);

        $details = $user->getDetails();
        $this->assertSame('partial', $details['deployment_status']);
        $this->assertCount(2, $details['deployment_warnings']);
        $this->assertStringContainsString('not reachable from the internet', $details['deployment_warnings'][0]);
        $this->assertStringContainsString('browsers will refuse', $details['deployment_warnings'][1]);
    }

    public function test_a_clean_rebuild_still_clears_old_warnings(): void
    {
        $user = $this->user([
            'domain' => [
                'source' => DomainPlan::SOURCE_PANELALPHA_ONLINE,
                'publicly_resolvable' => true,
                'tls_terminated_at' => 'proxy',
            ],
            'deployment_status' => 'partial',
            'deployment_warnings' => ['left by the first deploy'],
        ]);

        $this->record($user);

        $this->assertSame('success', $user->getDetails()['deployment_status']);
        $this->assertSame([], $user->getDetails()['deployment_warnings']);
    }
}
