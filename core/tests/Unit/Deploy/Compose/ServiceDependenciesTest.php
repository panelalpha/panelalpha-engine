<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\ServiceDependencies;
use PHPUnit\Framework\TestCase;

/**
 * `depends_on` after the services it names have been dropped.
 *
 * Compose refuses to start a stack that depends on a service the file does
 * not define, so dropping the workstation-only services and leaving the
 * references behind turns a reduction that was meant to help into a deploy
 * that never starts.
 */
class ServiceDependenciesTest extends TestCase
{
    public function test_a_dropped_service_is_removed_from_the_list_form(): void
    {
        $service = ServiceDependencies::withoutDropped(
            ['image' => 'acme/app', 'depends_on' => ['db', 'mailhog']],
            ['mailhog' => true]
        );

        $this->assertSame(['db'], $service['depends_on']);
    }

    public function test_a_dropped_service_is_removed_from_the_keyed_form(): void
    {
        // The long form carries a condition per dependency, and the ones that
        // survive must keep theirs.
        $service = ServiceDependencies::withoutDropped(
            [
                'depends_on' => [
                    'db' => ['condition' => 'service_healthy'],
                    'mailhog' => ['condition' => 'service_started'],
                ],
            ],
            ['mailhog' => true]
        );

        $this->assertSame(['db' => ['condition' => 'service_healthy']], $service['depends_on']);
    }

    public function test_an_empty_depends_on_is_removed_entirely(): void
    {
        // Compose rejects `depends_on: []` in some versions, and an empty key
        // is noise in the customer's file either way.
        $service = ServiceDependencies::withoutDropped(
            ['image' => 'acme/app', 'depends_on' => ['mailhog']],
            ['mailhog' => true]
        );

        $this->assertArrayNotHasKey('depends_on', $service);
        $this->assertSame(['image' => 'acme/app'], $service);
    }

    public function test_the_name_is_matched_case_insensitively(): void
    {
        $service = ServiceDependencies::withoutDropped(
            ['depends_on' => ['MailHog', 'db']],
            ['mailhog' => true]
        );

        $this->assertSame(['db'], $service['depends_on']);
    }

    public function test_the_kept_list_is_reindexed(): void
    {
        // A gap would serialise as a YAML map where compose wants a sequence.
        $service = ServiceDependencies::withoutDropped(
            ['depends_on' => ['mailhog', 'db', 'selenium', 'redis']],
            ['mailhog' => true, 'selenium' => true]
        );

        $this->assertSame([0, 1], array_keys($service['depends_on']));
    }

    public function test_a_service_that_depends_on_nothing_dropped_is_untouched(): void
    {
        $service = ['image' => 'acme/app', 'depends_on' => ['db']];

        $this->assertSame($service, ServiceDependencies::withoutDropped($service, ['mailhog' => true]));
    }

    public function test_nothing_dropped_means_nothing_to_do(): void
    {
        $service = ['depends_on' => ['db', 'mailhog']];

        $this->assertSame($service, ServiceDependencies::withoutDropped($service, []));
    }

    public function test_a_service_with_no_dependencies_is_untouched(): void
    {
        $service = ['image' => 'acme/app'];

        $this->assertSame($service, ServiceDependencies::withoutDropped($service, ['mailhog' => true]));
    }

    /**
     * `links` is the older spelling of the same reference, and Compose
     * enforces it just as strictly. Shlink is where the gap cost a deploy:
     * `shlink_php` is built from source, so the dockerfile strategy pruned
     * it, while `shlink_nginx` is a plain image that reaches it with
     * `links: - shlink_php`. The merged project was rejected outright --
     *
     *     service "shlink_nginx" depends on undefined service "shlink_php":
     *     invalid compose project
     *
     * -- after 75 seconds spent seeding images for a stack that could never
     * have started.
     */
    public function test_links_to_a_dropped_service_are_removed(): void
    {
        $service = ServiceDependencies::withoutDropped(
            ['image' => 'nginx:1.25-alpine', 'links' => ['shlink_php']],
            ['shlink_php' => true]
        );

        $this->assertArrayNotHasKey('links', $service, 'a dangling link invalidates the whole project');
        $this->assertSame('nginx:1.25-alpine', $service['image']);
    }

    /** `name:alias` is the same reference wearing a different name. */
    public function test_an_aliased_link_is_matched_on_the_service(): void
    {
        $service = ServiceDependencies::withoutDropped(
            ['links' => ['shlink_php:php', 'redis']],
            ['shlink_php' => true]
        );

        $this->assertSame(['redis'], $service['links']);
    }

    /** Links to services that survive are left alone. */
    public function test_links_to_kept_services_survive(): void
    {
        $service = ServiceDependencies::withoutDropped(
            ['links' => ['redis', 'db']],
            ['mailpit' => true]
        );

        $this->assertSame(['redis', 'db'], $service['links']);
    }

    /** Both keys are pruned in one pass, not one or the other. */
    public function test_depends_on_and_links_are_both_pruned(): void
    {
        $service = ServiceDependencies::withoutDropped(
            ['depends_on' => ['shlink_php', 'db'], 'links' => ['shlink_php']],
            ['shlink_php' => true]
        );

        $this->assertSame(['db'], $service['depends_on']);
        $this->assertArrayNotHasKey('links', $service);
    }

    /** RoboSats runs every service on `network_mode: service:tor`, and `tor` is built here. */
    public function test_a_dropped_network_or_volume_source_is_let_go(): void
    {
        $service = ServiceDependencies::withoutDropped(
            [
                'image' => 'redis:6.2.6',
                'network_mode' => 'service:tor',
                'volumes_from' => ['tor:ro', 'cache', 'container:tor'],
            ],
            ['tor' => true]
        );

        $this->assertArrayNotHasKey('network_mode', $service);
        $this->assertSame(['cache', 'container:tor'], $service['volumes_from']);

        $kept = ServiceDependencies::withoutDropped(
            ['network_mode' => 'service:vpn', 'volumes_from' => ['tor']],
            ['tor' => true]
        );
        $this->assertSame('service:vpn', $kept['network_mode']);
        $this->assertArrayNotHasKey('volumes_from', $kept);
    }

    public function test_a_rename_follows_network_mode_and_volumes_from(): void
    {
        $service = ServiceDependencies::renamed(
            ['network_mode' => 'service:app', 'volumes_from' => ['app:ro', 'container:app']],
            'app',
            'app-sidecar'
        );

        $this->assertSame('service:app-sidecar', $service['network_mode']);
        $this->assertSame(['app-sidecar:ro', 'container:app'], $service['volumes_from']);
    }
}
