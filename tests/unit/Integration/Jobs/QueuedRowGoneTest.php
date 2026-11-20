<?php

namespace Tests\Integration\Jobs;

use App\Jobs\MigrateWordpress;
use App\Jobs\RunHookDelivery;
use App\Lib\DeployHook\DeliveryRunner;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/** A queued job whose row was deleted before it ran does nothing. */
class QueuedRowGoneTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_migration_slice_for_a_deleted_migration_queues_no_next_slice(): void
    {
        Queue::fake();

        (new MigrateWordpress(404))->handle();

        Queue::assertNothingPushed();
    }

    public function test_a_failed_delivery_job_for_a_deleted_delivery_abandons_nothing(): void
    {
        $runner = Mockery::mock(DeliveryRunner::class);
        $runner->shouldNotReceive('abandon');
        $this->app->instance(DeliveryRunner::class, $runner);

        (new RunHookDelivery(404))->failed(new \RuntimeException('worker died'));
    }
}
