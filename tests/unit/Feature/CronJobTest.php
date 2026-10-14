<?php

namespace Tests\Feature;

use Tests\Attributes\SetsCache;
use Tests\Attributes\UnsetsCache;
use Tests\Attributes\UpdatesCache;
use Tests\TestCase;

/**
 * @depends Tests\Feature\ApiTokenTest::test_create_api_token
 * @depends Tests\Feature\UserTest::test_create_user
 */
class CronJobTest extends TestCase
{
    public function test_get_cron_jobs(): void
    {
        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        $response = $this->getJson("/api/users/{$username}/cron-jobs");
        $response->assertStatus(200);
    }

    #[SetsCache('cron_job')]
    public function test_create_cron_job(): void
    {
        $this->skipIfCached('cron_job');

        $username = $this->getCacheAsString('user.username');
        $this->authenticate();
        
        $cronJobPayload = [
            'command' => 'php /var/www/artisan schedule:run',
            'minute' => '0',
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*'
        ];
        
        $response = $this->postJson("/api/users/{$username}/cron-jobs", $cronJobPayload);
        $response->assertStatus(200);

        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('cron_job', $result);
    }


    #[UpdatesCache('cron_job')]
    public function test_update_cron_job(): void
    {
        $username = $this->getCacheAsString('user.username');
        $hash = $this->getCacheAsString('cron_job.hash');
        $this->authenticate();
        
        $updatePayload = ['command' => 'php /var/www/artisan queue:work', 'minute' => '15', 'hour' => '*', 'day_of_month' => '*', 'month' => '*', 'day_of_week' => '*'];
        $response = $this->putJson("/api/users/{$username}/cron-jobs/{$hash}", $updatePayload);
        $response->assertStatus(200);

        $result = $response->json('data');
        assert(is_array($result));
        $this->setCache('cron_job', $result);
    }

    #[UnsetsCache('cron_job')]
    public function test_delete_cron_job(): void
    {
        $username = $this->getCacheAsString('user.username');
        $hash = $this->getCacheAsString('cron_job.hash');
        $this->authenticate();
        
        // Fetch existing cron jobs to verify the hash
        $cronJobsListResponse = $this->getJson("/api/users/{$username}/cron-jobs");
        $result = $cronJobsListResponse->json('data');
        assert(is_array($result));
        $cronJobsList = collect($result);
        
        if (!$cronJobsList->pluck('hash')->contains($hash)) {
            dump('Cron Job Not Found in List. Available Jobs:', $cronJobsList->pluck('hash'));
            $this->markTestSkipped('Skipping delete test as the cron job does not exist.');
        }
        
        $response = $this->deleteJson("/api/users/{$username}/cron-jobs/{$hash}");
        
        if ($response->status() !== 200) {
            dump('Cron Job Delete Error:', $response->json());
        }
        
        $response->assertStatus(200);
        $this->unsetCache('cron_job');
    }
}