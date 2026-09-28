<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogger;
use Tests\TestCase;

/**
 * Unit tests for DeployLogger's logging/streaming/read/prune surface.
 *
 * DeployLoggerSecurityTest already covers locking, credential redaction and
 * cancel/pid-reuse safety — this file covers the rest: stage transitions,
 * the log-level wrappers, subprocess buffer handling (partial lines,
 * \r-progress collapse, dedup, noise filtering), read() pagination, the
 * NDJSON stream tee, message truncation, and the list/prune housekeeping
 * methods.
 *
 * storage_path() requires a booted app, so this extends the Laravel
 * Tests\TestCase like DeployLoggerSecurityTest does.
 */
class DeployLoggerTest extends TestCase
{
    /** @var list<string> */
    private array $usernames = [];

    protected function tearDown(): void
    {
        DeployLogger::stopStreaming();
        gc_collect_cycles();
        foreach ($this->usernames as $username) {
            DeployLogger::deleteUserLogs($username);
        }
        parent::tearDown();
    }

    private function username(): string
    {
        $username = 'logger-' . bin2hex(random_bytes(6));
        $this->usernames[] = $username;

        return $username;
    }

    public function test_stage_transitions_record_timestamps_and_emit_start_finish_lines(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->stage(DeployLogger::STAGE_PREPARING);
        $logger->stage(DeployLogger::STAGE_CLONING);
        $logger->stage(DeployLogger::STAGE_CLONING); // repeat: no-op

        $latest = $logger->readLatest();
        $this->assertSame(DeployLogger::STAGE_CLONING, $latest['stage']);
        $this->assertCount(2, $latest['stages']);
        $this->assertSame(DeployLogger::STAGE_PREPARING, $latest['stages'][0]['name']);
        $this->assertNotNull($latest['stages'][0]['finished_at']);
        $this->assertSame(DeployLogger::STAGE_CLONING, $latest['stages'][1]['name']);
        $this->assertNull($latest['stages'][1]['finished_at']);

        $messages = array_column($logger->read()['lines'], 'msg');
        $this->assertSame([
            'Starting stage: preparing',
            "Stage 'preparing' finished",
            'Starting stage: cloning',
        ], $messages);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_a_known_problem_is_written_to_the_log_line_by_line(): void
    {
        $logger = DeployLogger::start($this->username());
        $write = new \ReflectionMethod(DeployLogger::class, 'writeProblem');
        $write->invoke($logger, [
            'title' => 'Node 18 is too old',
            'body_why' => 'The lockfile needs npm 9.',
            'body_fix' => "Add to package.json:\n\n\"engines\": {\"node\": \"20\"}",
            'fixed_in_version' => '2.0.3',
        ], null);

        $lines = $logger->read()['lines'];
        $this->assertSame([
            'Known problem: Node 18 is too old',
            'Why:',
            'The lockfile needs npm 9.',
            'How to fix:',
            'Add to package.json:',
            '"engines": {"node": "20"}',
            'Fixed in engine version 2.0.3',
        ], array_column($lines, 'msg'));
        $this->assertSame(DeployLogger::LEVEL_WARN, $lines[0]['level']);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_a_finished_deploy_without_a_known_problem_says_so(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->finish(DeployLogger::STATUS_FAILED, 'boom');

        $latest = $logger->readLatest();
        $this->assertSame(DeployLogger::STATUS_FAILED, $latest['status']);
        $this->assertArrayHasKey('problem', $latest);
        $this->assertNull($latest['problem']);
    }

    public function test_log_level_wrappers_write_the_expected_level(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->dim('d');
        $logger->ok('o');
        $logger->info('i');
        $logger->warn('w');
        $logger->error('e');

        $lines = $logger->read()['lines'];
        $this->assertSame([
            DeployLogger::LEVEL_DIM,
            DeployLogger::LEVEL_OK,
            DeployLogger::LEVEL_INFO,
            DeployLogger::LEVEL_WARN,
            DeployLogger::LEVEL_ERROR,
        ], array_column($lines, 'level'));
        $this->assertSame(['d', 'o', 'i', 'w', 'e'], array_column($lines, 'msg'));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_write_process_buffer_holds_a_partial_line_until_a_newline_arrives(): void
    {
        $logger = DeployLogger::start($this->username());

        $logger->writeProcessBuffer('stdout', 'building a');
        $this->assertSame([], $logger->read()['lines']);

        $logger->writeProcessBuffer('stdout', "pp\n");
        $lines = $logger->read()['lines'];
        $this->assertSame(['building app'], array_column($lines, 'msg'));
        $this->assertSame(DeployLogger::LEVEL_DIM, $lines[0]['level']);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_write_process_buffer_collapses_carriage_return_progress_to_final_state(): void
    {
        $logger = DeployLogger::start($this->username());

        $logger->writeProcessBuffer('stdout', "progress 10%\rprogress 55%\rprogress 100%\n");

        $this->assertSame(['progress 100%'], array_column($logger->read()['lines'], 'msg'));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_write_process_buffer_dedupes_consecutive_identical_lines(): void
    {
        $logger = DeployLogger::start($this->username());

        $logger->writeProcessBuffer('stdout', "same line\nsame line\ndifferent\n");

        $this->assertSame(['same line', 'different'], array_column($logger->read()['lines'], 'msg'));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_write_process_buffer_skips_lines_filtered_as_noise(): void
    {
        $logger = DeployLogger::start($this->username());

        $logger->writeProcessBuffer('stdout', "#3 [internal] load build definition\nreal output\n");

        $this->assertSame(['real output'], array_column($logger->read()['lines'], 'msg'));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_flush_buffers_emits_remaining_partial_content_once(): void
    {
        $logger = DeployLogger::start($this->username());

        $logger->writeProcessBuffer('stdout', 'unterminated output');
        $this->assertSame([], $logger->read()['lines']);

        $logger->flushBuffers();
        $this->assertSame(['unterminated output'], array_column($logger->read()['lines'], 'msg'));

        // Nothing left buffered: flushing again writes nothing further.
        $logger->flushBuffers();
        $this->assertSame(['unterminated output'], array_column($logger->read()['lines'], 'msg'));

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_read_paginates_from_an_offset_and_next_offset_is_the_total_line_count(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->info('a');
        $logger->info('b');
        $logger->info('c');

        $first = $logger->read(0, 2);
        $this->assertSame(['a', 'b'], array_column($first['lines'], 'msg'));
        $this->assertSame(3, $first['next_offset']);

        $second = $logger->read(2, 2);
        $this->assertSame(['c'], array_column($second['lines'], 'msg'));
        $this->assertSame(3, $second['next_offset']);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_read_returns_empty_result_when_the_log_file_does_not_exist(): void
    {
        $logger = DeployLogger::forDeploy($this->username(), 'nonexistent-deploy-id');

        $this->assertSame(['lines' => [], 'next_offset' => 0], $logger->read());
    }

    public function test_message_longer_than_the_limit_is_truncated_with_an_ellipsis(): void
    {
        $logger = DeployLogger::start($this->username());
        $logger->info(str_repeat('a', 5000));

        $msg = $logger->read()['lines'][0]['msg'];
        $this->assertSame(4001, mb_strlen($msg));
        $this->assertSame(str_repeat('a', 4000), mb_substr($msg, 0, 4000));
        $this->assertStringEndsWith('…', $msg);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_stream_to_tees_lines_and_stage_frames_until_stopped(): void
    {
        $logger = DeployLogger::start($this->username());
        $frames = [];
        DeployLogger::streamTo(function (array $frame) use (&$frames): void {
            $frames[] = $frame;
        });

        try {
            $logger->stage(DeployLogger::STAGE_CLONING);
            $logger->info('cloning repo');
        } finally {
            DeployLogger::stopStreaming();
        }

        $stageFrames = array_values(array_filter($frames, static fn (array $f): bool => $f['type'] === 'stage'));
        $lineFrames = array_values(array_filter($frames, static fn (array $f): bool => $f['type'] === 'line'));
        $this->assertNotEmpty($stageFrames);
        $this->assertSame(DeployLogger::STAGE_CLONING, $stageFrames[0]['stage']);
        $this->assertContains('cloning repo', array_column($lineFrames, 'msg'));

        // Detached: further activity produces no more frames.
        $framesAfterStop = count($frames);
        $logger->info('after stop');
        $this->assertCount($framesAfterStop, $frames);

        $logger->finish(DeployLogger::STATUS_SUCCESS);
    }

    public function test_list_users_and_list_deploys_report_counts_and_latest_status(): void
    {
        $username = $this->username();
        $dir = DeployLogger::userDirFor($username);
        mkdir($dir, 0775, true);
        file_put_contents($dir . '/20260101-000000-aaa.log', "{}\n{}\n");
        file_put_contents($dir . '/20260102-000000-bbb.log', "{}\n");
        file_put_contents($dir . '/latest.json', json_encode([
            'id' => '20260102-000000-bbb',
            'status' => DeployLogger::STATUS_SUCCESS,
            'stage' => DeployLogger::STAGE_RUNNING,
        ]));

        $match = null;
        foreach (DeployLogger::listUsers() as $row) {
            if ($row['username'] === $username) {
                $match = $row;
                break;
            }
        }
        $this->assertNotNull($match, 'expected the seeded user to appear in listUsers()');
        $this->assertSame(2, $match['log_count']);
        $this->assertSame('20260102-000000-bbb', $match['id']);
        $this->assertSame(DeployLogger::STATUS_SUCCESS, $match['status']);
        $this->assertSame(DeployLogger::STAGE_RUNNING, $match['stage']);

        $deploys = DeployLogger::listDeploys($username);
        $this->assertCount(2, $deploys);
        $latestRow = null;
        foreach ($deploys as $d) {
            if ($d['is_latest']) {
                $latestRow = $d;
            }
        }
        $this->assertNotNull($latestRow, 'expected exactly one deploy flagged as latest');
        $this->assertSame('20260102-000000-bbb', $latestRow['id']);
        $this->assertSame(DeployLogger::STATUS_SUCCESS, $latestRow['status']);
    }

    public function test_prune_user_keeps_only_the_newest_n_logs(): void
    {
        $username = $this->username();
        $dir = DeployLogger::userDirFor($username);
        mkdir($dir, 0775, true);
        foreach (range(1, 5) as $day) {
            file_put_contents(sprintf('%s/202601%02d-000000-aaa.log', $dir, $day), '{}');
        }

        $deleted = DeployLogger::pruneUser($username, 3);

        $this->assertCount(2, $deleted);
        $remaining = array_map('basename', glob($dir . '/*.log') ?: []);
        sort($remaining);
        $this->assertSame([
            '20260103-000000-aaa.log',
            '20260104-000000-aaa.log',
            '20260105-000000-aaa.log',
        ], $remaining);
    }

    public function test_prune_user_dry_run_reports_without_deleting(): void
    {
        $username = $this->username();
        $dir = DeployLogger::userDirFor($username);
        mkdir($dir, 0775, true);
        foreach (range(1, 4) as $day) {
            file_put_contents(sprintf('%s/202601%02d-000000-aaa.log', $dir, $day), '{}');
        }

        $deleted = DeployLogger::pruneUser($username, 2, true);

        $this->assertCount(2, $deleted);
        $this->assertCount(4, glob($dir . '/*.log') ?: []);
    }

    public function test_prune_user_rejects_keep_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeployLogger::pruneUser($this->username(), 0);
    }

    public function test_prune_all_prunes_every_user_it_finds(): void
    {
        $userA = $this->username();
        $userB = $this->username();
        foreach ([$userA, $userB] as $u) {
            $dir = DeployLogger::userDirFor($u);
            mkdir($dir, 0775, true);
            foreach (range(1, 4) as $day) {
                file_put_contents(sprintf('%s/202601%02d-000000-aaa.log', $dir, $day), '{}');
            }
        }

        $result = DeployLogger::pruneAll(2);

        $this->assertArrayHasKey($userA, $result);
        $this->assertArrayHasKey($userB, $result);
        $this->assertCount(2, $result[$userA]);
        $this->assertCount(2, glob(DeployLogger::userDirFor($userA) . '/*.log') ?: []);
    }
}
