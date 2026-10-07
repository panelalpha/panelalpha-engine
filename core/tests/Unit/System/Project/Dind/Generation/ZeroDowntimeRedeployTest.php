<?php

namespace Tests\Unit\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\Health\ProbedResponse;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use PHPUnit\Framework\TestCase;

/**
 * The readings the swap acts on -- where Docker published the
 * second generation, whether a container is going to answer at all, and
 * which way traffic goes back.
 */
class ZeroDowntimeRedeployTest extends TestCase
{
    public function test_the_port_docker_chose_is_read_ipv4_first(): void
    {
        $this->assertSame(32771, ZeroDowntimeRedeploy::boundPort("[::]:32772\n0.0.0.0:32771\n"));
        $this->assertSame(32772, ZeroDowntimeRedeploy::boundPort("[::]:32772\n"));
        $this->assertNull(ZeroDowntimeRedeploy::boundPort(''));
    }

    public function test_a_running_container_has_not_failed_yet(): void
    {
        $this->assertNull(ZeroDowntimeRedeploy::containerFailure('running 0 0'));
        $this->assertNull(ZeroDowntimeRedeploy::containerFailure("created 0 0\n"));
        // Docker's code for a start that failed elsewhere than on the image's command (the network, the daemon).
        $this->assertNull(ZeroDowntimeRedeploy::containerFailure('created 128 0'));
    }

    public function test_a_container_that_stopped_or_loops_will_not_answer(): void
    {
        $this->assertSame('it exited with code 1', ZeroDowntimeRedeploy::containerFailure('exited 1 0'));
        $this->assertSame('it keeps restarting (last exit code 1)', ZeroDowntimeRedeploy::containerFailure('restarting 1 3'));
        $this->assertSame('it keeps restarting (2 restarts so far)', ZeroDowntimeRedeploy::containerFailure('running 0 2'));
        $this->assertSame('its container is gone', ZeroDowntimeRedeploy::containerFailure(''));
        $this->assertSame('it could not be started (exit code 127)', ZeroDowntimeRedeploy::containerFailure('created 127 0'));
        $this->assertSame('it could not be started (exit code 126)', ZeroDowntimeRedeploy::containerFailure('created 126 0'));
    }

    public function test_traffic_goes_back_the_way_it_came(): void
    {
        $this->assertSame([32771 => 3000, 32772 => 9000], ZeroDowntimeRedeploy::inverse([3000 => 32771, 9000 => 32772]));
    }

    public function test_a_kept_previous_version_is_still_a_failed_start(): void
    {
        $result = ZeroDowntimeRedeploy::previousKept('', 'build failed', 0);

        $this->assertSame(1, $result['exit_code']);
        $this->assertTrue($result[ZeroDowntimeRedeploy::PREVIOUS_KEPT]);
    }

    public function test_the_old_checkout_comes_back_only_while_all_its_readers_still_run(): void
    {
        $this->assertTrue(CheckoutAside::allTrue("true\ntrue\n", 2));
        $this->assertFalse(CheckoutAside::allTrue("true\ngone\n", 2), 'one was replaced');
        $this->assertFalse(CheckoutAside::allTrue("true\nfalse\n", 2));
        $this->assertFalse(CheckoutAside::allTrue("true\n", 2), 'an answer missing');
    }

    /** A redeploy pulls before it replaces anything; what it builds itself is not pulled. */
    public function test_only_registry_images_are_pulled_ahead(): void
    {
        $config = [
            'name' => 'project',
            'services' => [
                'web' => ['image' => 'traefik/whoami:v0.0.0-missing'],
                'app' => ['build' => ['context' => '.']],
                'worker' => ['image' => 'project-app:latest'],
                'api' => ['build' => ['context' => './api'], 'image' => 'acme/api:2'],
                'cli' => ['image' => 'acme/api:2'],
                'local' => ['image' => 'local-only', 'pull_policy' => 'never'],
                'db' => ['image' => 'postgres:16'],
            ],
        ];

        $this->assertSame(['web', 'db'], ZeroDowntimeRedeploy::servicesToPull($config));
        $this->assertSame([], ZeroDowntimeRedeploy::servicesToPull(['services' => []]));
    }

    /** A failed redeploy leaves the project as it was; a successful one keeps what it deployed. */
    public function test_the_old_checkout_comes_back_only_after_a_failure_that_kept_its_containers(): void
    {
        $this->assertSame('restored', CheckoutAside::outcome(false, true), 'failed, old version still serving');
        $this->assertSame('removed', CheckoutAside::outcome(false, false), 'failed after the old version was replaced');
        $this->assertSame('removed', CheckoutAside::outcome(true, true), 'succeeded without recreating anything');
        $this->assertSame('restored', CheckoutAside::outcome(null, true), 'died with the old version serving');
        $this->assertSame('removed', CheckoutAside::outcome(null, false));
    }

    /** A new version on another port: the site moves from where it is to the copy of the new port. */
    public function test_the_switch_starts_from_the_port_the_site_is_on(): void
    {
        $this->assertSame([3000 => 32771], ZeroDowntimeRedeploy::switchMap([3000 => 32771], 3000, 3000));
        $this->assertSame([3000 => 32771], ZeroDowntimeRedeploy::switchMap([8080 => 32771], 8080, 3000));
        // A rule to another port of the app is not the site's route, and stays where it is.
        $this->assertSame([3000 => 32771], ZeroDowntimeRedeploy::switchMap([3000 => 32771, 9000 => 32772], 3000, 3000));
        // Moving back after a failure lands on the port the running version answers on.
        $this->assertSame([32771 => 3000], ZeroDowntimeRedeploy::inverse(ZeroDowntimeRedeploy::switchMap([8080 => 32771], 8080, 3000)));
        // Taking traffic lands on the new version's own port.
        $this->assertSame([32771 => 8080], ZeroDowntimeRedeploy::inverse([8080 => 32771]));
    }

    /** One line of the probe script's output, as the gate parses it. */
    private static function probed(string $codeAndTime, string $body): array
    {
        return AppHealth::parseProbeOutput("32768\thttp\t{$codeAndTime}\t\t" . base64_encode($body) . "\t/\n", [32768])[0];
    }

    /** An empty 200 answers, and moving traffic to it would serve every visitor a blank page. */
    public function test_a_new_version_answering_an_empty_page_does_not_take_traffic(): void
    {
        $this->assertSame(
            ['status' => 'fail', 'http_code' => 200, 'detail' => 'HTTP 200 with an empty page'],
            ZeroDowntimeRedeploy::gateAnswer(self::probed('200 0.002', ''))
        );
        $this->assertSame('fail', ZeroDowntimeRedeploy::gateAnswer(self::probed('200 0.002', "\n"))['status']);
    }

    public function test_a_page_with_content_or_no_content_on_purpose_takes_traffic(): void
    {
        foreach ([[200, '<html>v2</html>'], [200, '{}'], [200, '[]'], [200, 'ok'], [204, ''], [302, ''], [404, '']] as [$code, $body]) {
            $answer = ZeroDowntimeRedeploy::gateAnswer(self::probed("{$code} 0.002", $body));

            $this->assertSame(['status' => 'ok', 'http_code' => $code, 'detail' => "HTTP {$code}"], $answer, "{$code} '{$body}'");
        }
    }

    /** The probe brings back 4096 bytes; whitespace filling all of them is the start of a longer page. */
    public function test_a_page_padded_past_the_sample_takes_traffic(): void
    {
        $answer = ZeroDowntimeRedeploy::gateAnswer(self::probed('200 0.002', str_repeat("\n", ProbedResponse::SAMPLE_BYTES)));

        $this->assertSame(['status' => 'ok', 'http_code' => 200, 'detail' => 'HTTP 200'], $answer);
    }

    /** Refused for an empty page, the new version did start: the failure says what it served and that the old one serves. */
    public function test_a_refusal_for_an_empty_page_says_so_and_names_its_rule(): void
    {
        foreach (['it answers HTTP 200 with an empty page', 'nothing answered within 300 s (HTTP 200 with an empty page)'] as $failure) {
            $refusal = ZeroDowntimeRedeploy::refusal($failure);

            $this->assertStringStartsWith(DeployFailureExplainer::EMPTY_NEW_VERSION, $refusal);
            $this->assertStringContainsString('the previous version is still serving', $refusal);
            $this->assertStringContainsString('A route meant to send nothing answers 204.', $refusal);
            $this->assertStringNotContainsString('healthy', $refusal);
            $this->assertSame(['rule' => 'new-version-empty-page', 'message' => $refusal], DeployFailureExplainer::match($refusal));
        }
    }

    public function test_other_refusals_read_as_before(): void
    {
        $this->assertSame(
            'The new version did not become healthy: it answers HTTP 502. The previous version is still serving.',
            ZeroDowntimeRedeploy::refusal('it answers HTTP 502')
        );
        $this->assertSame(
            'The new version did not become healthy: it exited with code 1. The previous version is still serving.',
            ZeroDowntimeRedeploy::refusal('it exited with code 1')
        );
    }

    public function test_a_server_error_or_silence_still_fails_the_gate_as_before(): void
    {
        $this->assertSame(
            ['status' => 'fail', 'http_code' => 502, 'detail' => 'HTTP 502'],
            ZeroDowntimeRedeploy::gateAnswer(self::probed('502 0.001', ''))
        );
        $silent = ZeroDowntimeRedeploy::gateAnswer(AppHealth::parseProbeOutput("32768\thttp\t000 0\tConnection refused\t\t/\n", [32768])[0]);
        $this->assertSame('fail', $silent['status']);
        $this->assertNull($silent['http_code']);
    }
}
