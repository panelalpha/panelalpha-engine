<?php

namespace Tests\Unit\Deploy\Sidecar;

use App\Lib\Deploy\Sidecar\ServiceRole;
use PHPUnit\Framework\TestCase;

/**
 * Which service in a compose file is the application, and which are the
 * things it depends on.
 *
 * The class asks this way round on purpose. The earlier version asked "is
 * this one of the datastores I know?" and dropped everything else, so a
 * project built on SurrealDB or QuestDB deployed with no database at all.
 *
 * The two questions have opposite defaults, and the tests are grouped to keep
 * that visible: isKnownDatastore() defaults to no, because a wrong yes
 * filters the app's own port and leaves the site unreachable; isBacking()
 * defaults to yes, because a wrong no drops the database.
 */
class ServiceRoleTest extends TestCase
{
    private const POSTGRES = ['image' => 'postgres:16', 'ports' => ['5432:5432']];

    private const APP = ['image' => 'ghcr.io/acme/shop:latest', 'ports' => ['8080:8080']];

    public function test_a_catalogued_image_is_recognisably_a_datastore(): void
    {
        $this->assertTrue(ServiceRole::isKnownDatastore('db', self::POSTGRES));
        $this->assertTrue(ServiceRole::isKnownDatastore('anything', ['image' => 'redis:7']));
    }

    public function test_an_unambiguous_port_is_enough_on_its_own(): void
    {
        // internal/store:1 answering on 5432 is a Postgres, whatever its
        // image is called.
        $this->assertTrue(ServiceRole::isKnownDatastore('store', [
            'image' => 'internal/store:1',
            'ports' => ['5432:5432'],
        ]));
    }

    public function test_an_observed_port_counts_as_much_as_a_declared_one(): void
    {
        $this->assertTrue(ServiceRole::isKnownDatastore('store', ['image' => 'internal/store:1'], [3306]));
    }

    public function test_the_application_is_not_recognisably_a_datastore(): void
    {
        $this->assertFalse(ServiceRole::isKnownDatastore('web', self::APP));
    }

    public function test_an_uncatalogued_datastore_is_not_recognised_here(): void
    {
        // And that is the correct answer for this question: nothing about
        // surrealdb:latest proves it is not the app, and guessing wrong here
        // would take the site down.
        $this->assertFalse(ServiceRole::isKnownDatastore('db', ['image' => 'surrealdb/surrealdb:latest']));
    }

    public function test_an_uncatalogued_datastore_is_still_kept_as_backing(): void
    {
        // The whole reason the class exists. It is not the application, so it
        // is started alongside it rather than dropped.
        $this->assertTrue(ServiceRole::isBacking('db', ['image' => 'surrealdb/surrealdb:latest'], [], [
            'app' => self::APP,
            'db' => ['image' => 'surrealdb/surrealdb:latest'],
        ]));
    }

    public function test_a_service_built_from_the_repository_is_not_backing(): void
    {
        $this->assertFalse(ServiceRole::isBacking('app', ['build' => '.']));
    }

    public function test_the_service_that_reaches_for_the_others_is_the_application(): void
    {
        // Structural, not a matter of naming: the app declares depends_on,
        // databases are depended upon. Holds for projects nobody has heard of.
        $siblings = [
            'frontend' => ['image' => 'internal/frontend:1', 'depends_on' => ['store']],
            'store' => ['image' => 'internal/store:1'],
        ];

        $this->assertTrue(ServiceRole::isApplication('frontend', $siblings['frontend'], $siblings));
        $this->assertFalse(ServiceRole::isApplication('store', $siblings['store'], $siblings));
    }

    public function test_a_service_something_else_depends_on_is_not_the_top_of_the_stack(): void
    {
        // A middle tier: it depends on the store and the gateway depends on
        // it. Only the service nothing points at is the application.
        $siblings = [
            'gateway' => ['image' => 'internal/gateway:1', 'depends_on' => ['api']],
            'api' => ['image' => 'internal/api:1', 'depends_on' => ['store']],
            'store' => ['image' => 'internal/store:1'],
        ];

        $this->assertFalse(ServiceRole::isApplication('api', $siblings['api'], $siblings));
        $this->assertTrue(ServiceRole::isApplication('gateway', $siblings['gateway'], $siblings));
    }

    public function test_a_catalogued_datastore_that_depends_on_something_is_still_infrastructure(): void
    {
        // Redis with a depends_on is still Redis. The depends_on shape is the
        // last signal consulted, not the first.
        $siblings = [
            'app' => self::APP,
            'redis' => ['image' => 'redis:7', 'depends_on' => ['app']],
        ];

        $this->assertFalse(ServiceRole::isApplication('redis', $siblings['redis'], $siblings));
    }

    public function test_services_sharing_an_image_are_one_application_run_three_ways(): void
    {
        // web, worker and scheduler are the same build with different
        // commands. A datastore appears exactly once.
        $siblings = [
            'web' => ['image' => 'internal/app:1', 'command' => 'serve'],
            'worker' => ['image' => 'internal/app:1', 'command' => 'work'],
            'db' => self::POSTGRES,
        ];

        $this->assertTrue(ServiceRole::isApplication('web', $siblings['web'], $siblings));
        $this->assertTrue(ServiceRole::isApplication('worker', $siblings['worker'], $siblings));
    }

    public function test_the_published_build_of_the_repository_is_the_application(): void
    {
        // No depends_on, no sibling sharing the image: the only thing that
        // says which service is the app is that its image is the repo's name.
        $this->assertTrue(ServiceRole::isApplication(
            'anything',
            ['image' => 'ghcr.io/acme/shop:latest'],
            [],
            'github.com/acme/shop'
        ));
    }

    public function test_an_official_image_matches_a_same_named_repository(): void
    {
        // `listmonk/listmonk` published as the unqualified `listmonk`.
        $this->assertTrue(ServiceRole::isApplication(
            'app',
            ['image' => 'listmonk:latest'],
            [],
            'github.com/listmonk/listmonk'
        ));
    }

    public function test_an_unrelated_image_is_not_the_repositorys_build(): void
    {
        $this->assertFalse(ServiceRole::isApplication(
            'app',
            ['image' => 'nginx:alpine'],
            [],
            'github.com/acme/shop'
        ));
    }

    public function test_a_lone_service_asked_in_isolation_cannot_be_shown_to_be_the_app(): void
    {
        // With no siblings there is no structure to read, so the answer falls
        // back to "keep it" rather than "it must be the app".
        $this->assertFalse(ServiceRole::isApplication('app', ['image' => 'internal/app:1'], []));
    }

    public function test_a_stack_is_split_into_one_app_and_its_dependencies(): void
    {
        $siblings = [
            'app' => ['image' => 'internal/shop:1', 'depends_on' => ['db', 'cache', 'search']],
            'db' => self::POSTGRES,
            'cache' => ['image' => 'redis:7'],
            'search' => ['image' => 'getmeili/meilisearch:v1.5'],
        ];

        $backing = [];
        foreach ($siblings as $name => $service) {
            $backing[$name] = ServiceRole::isBacking($name, $service, [], $siblings);
        }

        $this->assertSame(
            ['app' => false, 'db' => true, 'cache' => true, 'search' => true],
            $backing
        );
    }

    public function test_a_named_engine_is_provided_by_the_stack(): void
    {
        $this->assertTrue(ServiceRole::servicesProvide(['db' => self::POSTGRES], 'postgres'));
        $this->assertFalse(ServiceRole::servicesProvide(['db' => self::POSTGRES], 'mysql'));
    }

    public function test_an_alias_counts_as_the_engine_it_names(): void
    {
        // A stack shipping MariaDB already provides MySQL; adding a second
        // sidecar for it would be a duplicate database.
        $this->assertTrue(ServiceRole::servicesProvide(['db' => ['image' => 'mariadb:11']], 'mysql'));
    }

    /**
     * A one-shot job is not the application, even though it shares the app's
     * image — which is exactly how dpaste's `migration` presents itself.
     *
     * Left out of this answer, both services claim to be the app: the mounts
     * and environment the deploy carries are then read off whichever came
     * first, and dpaste's job mounts the project at /app and carries no
     * DATABASE_URL while its app carries the URL and mounts the database
     * volume. The replacement then starts with no database.
     */
    public function test_a_job_sharing_the_app_image_is_not_the_application(): void
    {
        $siblings = [
            'app' => ['image' => 'app', 'ports' => ['8000:8000'], 'command' => './manage.py runserver 0:8000'],
            'migration' => ['image' => 'app', 'command' => './manage.py migrate --noinput'],
        ];

        $this->assertTrue(ServiceRole::isApplication('app', $siblings['app'], $siblings));
        $this->assertFalse(ServiceRole::isApplication('migration', $siblings['migration'], $siblings));
        // …and it is still kept: a deploy that drops it never migrates.
        $this->assertTrue(ServiceRole::isBacking('migration', $siblings['migration'], [], $siblings));
    }

    public function test_a_job_name_is_not_enough_without_a_setup_command(): void
    {
        // A real service called `migrate` that serves on a port is a service,
        // not a job, and switching it off would be a serious mistake.
        $this->assertFalse(ServiceRole::isJobService('migrate', [
            'image' => 'internal/shop:1',
            'ports' => ['8080:8080'],
            'command' => './manage.py migrate',
        ]));
        // A name nobody uses is left alone however it is commanded.
        $this->assertFalse(ServiceRole::isJobService('web', ['image' => 'app', 'command' => './manage.py migrate']));
        // A setup verb with a healthcheck is a service that waits on itself.
        $this->assertFalse(ServiceRole::isJobService('migration', [
            'image' => 'app',
            'command' => './manage.py migrate',
            'healthcheck' => ['test' => ['CMD', 'true']],
        ]));
        // An image-only service in the job position is the YAML anchor case,
        // which the base-service rule already covers.
        $this->assertFalse(ServiceRole::isJobService('migration', ['image' => 'app']));
    }

    public function test_a_job_is_recognised_by_its_command_as_well_as_its_name(): void
    {
        $this->assertTrue(ServiceRole::isJobService('migration', ['command' => './manage.py migrate --noinput']));
        $this->assertTrue(ServiceRole::isJobService('init', ['entrypoint' => 'sh -c "python seed.py"']));
        $this->assertFalse(ServiceRole::isJobService('migration', ['command' => './manage.py runserver 0:8000']));
    }
}
