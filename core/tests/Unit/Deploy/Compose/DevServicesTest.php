<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\DevServices;
use PHPUnit\Framework\TestCase;

/**
 * Services that exist for a developer's workstation and have no place in a
 * deployment.
 *
 * A mail catcher in production swallows every outgoing email silently; a Vite
 * dev server serves unbundled sources and holds a websocket open forever; a
 * tunnel publishes the account to the internet. All of them start cleanly,
 * which is what makes them worth recognising here rather than in an incident.
 */
class DevServicesTest extends TestCase
{
    public function test_workstation_services_are_recognised_by_name(): void
    {
        foreach (['vite', 'webpack', 'mailhog', 'mailpit', 'selenium', 'cypress', 'storybook', 'ngrok'] as $name) {
            $this->assertTrue(DevServices::isDevSidecar($name, ['image' => 'x']), $name);
        }
    }

    public function test_the_name_is_matched_case_insensitively(): void
    {
        $this->assertTrue(DevServices::isDevSidecar('MailHog', ['image' => 'mailhog/mailhog']));
    }

    public function test_a_dev_server_under_a_project_specific_name_is_recognised(): void
    {
        // Half of these appear as `acme-assets` or `frontend`, so the name
        // alone is not enough - what it runs gives it away.
        $this->assertTrue(DevServices::isDevSidecar('assets', ['command' => 'vite dev --host']));
        $this->assertTrue(DevServices::isDevSidecar('frontend', ['command' => ['npx', 'vite', 'serve']]));
        $this->assertTrue(DevServices::isDevSidecar('ui', ['command' => 'storybook dev -p 6006']));
    }

    public function test_a_production_build_is_not_a_dev_server(): void
    {
        // The same binary, the other subcommand. `vite build` writes assets
        // and exits, which is exactly what a deploy wants.
        $this->assertFalse(DevServices::isDevSidecar('assets', ['command' => 'vite build']));
        $this->assertFalse(DevServices::isDevSidecar('assets', ['command' => 'vite preview']));
    }

    public function test_an_ordinary_service_is_kept(): void
    {
        $this->assertFalse(DevServices::isDevSidecar('app', ['image' => 'ghcr.io/acme/shop:latest']));
        $this->assertFalse(DevServices::isDevSidecar('db', ['image' => 'postgres:16']));
    }

    public function test_a_service_that_declares_no_command_is_judged_on_its_name_alone(): void
    {
        $this->assertFalse(DevServices::isDevSidecar('worker', ['image' => 'ghcr.io/acme/shop:latest']));
    }

    public function test_a_tool_is_recognised_by_its_image_under_any_service_name(): void
    {
        $this->assertTrue(DevServices::isDevSidecar('debug', ['image' => 'ghcr.io/buggregator/server:latest']));
        $this->assertTrue(DevServices::isDevSidecar('mail', ['image' => 'axllent/mailpit']));
        $this->assertTrue(DevServices::isDevSidecar('browser', ['image' => 'selenium/standalone-chrome:4']));
        $this->assertTrue(DevServices::isDevSidecar('buggregator', ['image' => 'x']));
    }

    public function test_an_image_that_merely_contains_a_tool_word_is_not_a_tool(): void
    {
        $this->assertFalse(DevServices::isDevSidecar('app', ['image' => 'ghcr.io/acme/mailserver:1']));
        $this->assertFalse(DevServices::isDevSidecar('db', ['image' => 'postgres:16']));
    }
}
