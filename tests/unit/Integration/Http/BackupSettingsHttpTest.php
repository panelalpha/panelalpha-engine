<?php

namespace Tests\Integration\Http;

use App\Http\Middleware\Authenticate;
use App\Lib\Backup\ArchivePriority;
use App\Models\Setting;
use Tests\TestCase;

/**
 * `GET`/`PUT /system/backup-settings` read and write the engine's settings,
 * which is what the archive commands read when they start.
 */
class BackupSettingsHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Authenticate::class);
        Setting::setRuntimeSettings([
            ArchivePriority::SETTING_NICE => null,
            ArchivePriority::SETTING_IONICE_CLASS => null,
        ]);
    }

    protected function tearDown(): void
    {
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_a_fresh_engine_runs_its_archives_at_its_own_priority(): void
    {
        $response = $this->getJson('/api/system/backup-settings');

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertNull($response->json('data.nice'));
        $this->assertNull($response->json('data.ionice_class'));
    }

    public function test_setting_the_two_values_stores_them_where_the_archives_read_them(): void
    {
        $response = $this->putJson('/api/system/backup-settings', ['nice' => 10, 'ionice_class' => 3]);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertSame(10, $response->json('data.nice'));
        $this->assertSame(3, $response->json('data.ionice_class'));
        $this->assertSame(['nice', '-n', '10', 'ionice', '-c', '3'], ArchivePriority::configured()->prefix());
    }

    public function test_what_was_written_is_what_is_read_back(): void
    {
        $this->putJson('/api/system/backup-settings', ['nice' => 15, 'ionice_class' => 2]);

        $response = $this->getJson('/api/system/backup-settings');

        $this->assertSame(['nice' => 15, 'ionice_class' => 2], $response->json('data'));
    }

    public function test_a_field_left_out_is_left_as_it_was(): void
    {
        $this->putJson('/api/system/backup-settings', ['nice' => 10, 'ionice_class' => 3]);

        $response = $this->putJson('/api/system/backup-settings', ['nice' => 5]);

        $this->assertSame(['nice' => 5, 'ionice_class' => 3], $response->json('data'));
    }

    public function test_a_null_switches_that_half_off_again(): void
    {
        $this->putJson('/api/system/backup-settings', ['nice' => 10, 'ionice_class' => 3]);

        $response = $this->putJson('/api/system/backup-settings', ['nice' => null]);

        $this->assertNull($response->json('data.nice'));
        $this->assertSame(3, $response->json('data.ionice_class'));
        $this->assertNull(Setting::get(ArchivePriority::SETTING_NICE));
    }

    public function test_a_value_out_of_range_is_refused(): void
    {
        foreach ([['nice' => 20], ['nice' => -1], ['ionice_class' => 1], ['ionice_class' => 4]] as $body) {
            $response = $this->putJson('/api/system/backup-settings', $body);

            $this->assertSame(422, $response->getStatusCode(), json_encode($body).' was accepted');
        }
        $this->assertSame([], ArchivePriority::configured()->prefix());
    }
}
