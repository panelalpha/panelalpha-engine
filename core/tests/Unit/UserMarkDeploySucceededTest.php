<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\TestCase;

class UserMarkDeploySucceededTest extends TestCase
{
    public function test_a_clean_deploy_stores_an_empty_warnings_list(): void
    {
        $user = new User();

        $user->markDeploySucceeded();

        $details = $user->getDetails();
        $this->assertSame('success', $details['deployment_status']);
        $this->assertSame([], $details['deployment_warnings']);
    }

    public function test_it_replaces_warnings_left_by_an_earlier_partial_deploy(): void
    {
        $user = new User();
        $user->setDetails([
            'deployment_status' => 'partial',
            'deployment_warnings' => ['The app did not answer'],
            'app_port' => 8000,
        ]);

        $user->markDeploySucceeded();

        $details = $user->getDetails();
        $this->assertSame('success', $details['deployment_status']);
        $this->assertSame([], $details['deployment_warnings']);
        $this->assertSame(8000, $details['app_port'], 'other details are left alone');
    }

    public function test_it_clears_the_error_left_by_an_earlier_failed_deploy(): void
    {
        $user = new User();
        $user->setDetails([
            'deployment_status' => 'failed',
            'error' => 'The repository started answering and then stopped partway through the clone.',
        ]);

        $user->markDeploySucceeded();

        $details = $user->getDetails();
        $this->assertSame('success', $details['deployment_status']);
        $this->assertNull($details['error']);
    }
}
