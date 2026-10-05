<?php

namespace Tests\Unit\System\Project\Dind;

use App\System\Project\Dind\AppHealth;
use PHPUnit\Framework\TestCase;

class AppHealthTest extends TestCase
{
    public function test_probe_script_probes_every_port_over_loopback(): void
    {
        $script = AppHealth::probeScript([8080, 8443], 4, 2, 1);

        $this->assertStringContainsString('for port in 8080 8443;', $script);
        $this->assertStringContainsString('--max-time 4', $script);
        $this->assertStringContainsString('-ge 2', $script);
        $this->assertStringContainsString('sleep 1', $script);
        // Loopback inside the account container: never the domain, never the
        // proxy. The path is a variable now, because the probe follows a
        // relative redirect to reach the page a visitor actually lands on --
        // but the host it dials never moves off 127.0.0.1.
        $this->assertStringContainsString('"$scheme://127.0.0.1:$2$path"', $script);
        $this->assertStringContainsString('path=/', $script);
        // The scheme stays whatever the caller passed in, even when a
        // redirect named another: the port dialled is the container's own
        // Apache, and following a Location's `https` there fails the
        // handshake and reports 000 for a healthy site.
        $this->assertStringNotContainsString('scheme=$new_scheme', $script);
        $this->assertSame(
            substr_count($script, '://'),
            substr_count($script, '://127.0.0.1:'),
            'every URL the probe builds is loopback'
        );
    }

    /**
     * Firefly III answers `/` with `Location: https://127.0.0.1:8000/login`
     * -- absolute, and loopback. A probe that only followed `Location: /login`
     * reported 302 for that site, and the login page behind it was the 500.
     */
    public function test_probe_script_follows_an_absolute_redirect_only_when_it_is_local(): void
    {
        $script = AppHealth::probeScript([8000]);

        $this->assertStringContainsString('rest=${location#*//}', $script);
        $this->assertStringContainsString('[ "$host" = 127.0.0.1 ] || [ "$host" = localhost ]', $script);
        $this->assertStringContainsString('[ -z "$port" ] || [ "$port" = "$2" ]', $script);
        // A public name is not an address this probe may dial: following it
        // would take the check out of the container and onto the internet.
        $this->assertStringContainsString('follow=0', $script);
        $this->assertStringNotContainsString('example.com', $script);
    }

    public function test_probe_script_sends_the_project_domain_as_host_but_dials_loopback(): void
    {
        $script = AppHealth::probeScript([8000], 4, 2, 1, 'Shop.Example.com');

        $this->assertStringContainsString("-H 'Host: shop.example.com' ", $script);
        $this->assertStringContainsString('"$scheme://127.0.0.1:$2$path"', $script);
        $this->assertStringNotContainsString('Host:', AppHealth::probeScript([8000], 4, 2, 1));
        $this->assertStringNotContainsString('Host:', AppHealth::probeScript([8000], 4, 2, 1, '  '));
    }

    public function test_probe_script_asks_as_a_visitor_on_https_does(): void
    {
        // Squidex: antiforgery refuses a request that is not secure, and the
        // vhost marks a visitor's request as https.
        $script = AppHealth::probeScript([5000], 4, 2, 1, 'squidex.example.com');

        $this->assertStringContainsString("-H 'X-Forwarded-Proto: https' ", $script);
        $this->assertStringContainsString("-H 'X-Forwarded-Host: squidex.example.com' ", $script);
        $this->assertStringContainsString("-H 'X-Forwarded-Port: 443' ", $script);
        $this->assertStringContainsString("-H 'X-Forwarded-For: 192.0.2.1' ", $script);
        $this->assertStringContainsString("-H 'X-Real-IP: 192.0.2.1' ", $script);
        $this->assertStringNotContainsString('X-Forwarded', AppHealth::probeScript([5000], 4, 2, 1));
    }

    public function test_probe_script_falls_back_to_https_only_when_http_answers_nothing(): void
    {
        $script = AppHealth::probeScript([3000]);

        $this->assertStringContainsString("scheme=http\n        other=https", $script);
        $this->assertStringContainsString('probe "$scheme" "$port"', $script);
        $this->assertStringContainsString('probe "$other" "$port"', $script);
        $this->assertStringContainsString('if [ "${result%% *}" = "000" ]; then', $script);
    }

    public function test_probe_script_rejects_nonsense_tuning(): void
    {
        $script = AppHealth::probeScript([80], 0, 0, -5);

        $this->assertStringContainsString('--max-time 1', $script);
        $this->assertStringContainsString('-ge 1', $script);
        $this->assertStringContainsString('sleep 0', $script);
    }

    public function test_parses_a_successful_probe(): void
    {
        $results = AppHealth::parseProbeOutput("8080\thttp\t200 0.031482\t\n", [8080]);

        $this->assertSame([[
            'port' => 8080,
            'scheme' => 'http',
            'status' => AppHealth::STATUS_OK,
            'http_code' => 200,
            'time' => 0.031,
            'detail' => 'HTTP 200',
            // Evidence for the checks, stripped again before the report is
            // returned: no probe output means an empty sample, never a
            // missing key the runner would have to guard against.
            'body' => '',
            // Evidence too: a line without the path field answered at `/`.
            'path' => '/',
        ]], $results);
    }

    /**
     * The body sample the content checks read. base64 because a page holding
     * a tab or a newline would otherwise be parsed as three more ports.
     */
    public function test_it_decodes_the_body_sample_the_probe_brings_back(): void
    {
        $page = '<title>PanelAlpha — Ready</title>';
        $results = AppHealth::parseProbeOutput(
            "8080\thttp\t200 0.01\t\t" . base64_encode($page) . "\n",
            [8080]
        );

        $this->assertSame($page, $results[0]['body']);
    }

    public function test_an_unreadable_body_sample_is_no_sample_rather_than_an_error(): void
    {
        $results = AppHealth::parseProbeOutput("8080\thttp\t200 0.01\t\t!!!not-base64!!!\n", [8080]);

        $this->assertSame('', $results[0]['body']);
    }

    public function test_a_404_is_healthy_but_a_502_is_not(): void
    {
        $results = AppHealth::parseProbeOutput(
            "8080\thttp\t404 0.01\t\n8081\thttp\t502 0.02\t\n",
            [8080, 8081]
        );

        $this->assertSame(AppHealth::STATUS_OK, $results[0]['status']);
        $this->assertSame(AppHealth::STATUS_FAIL, $results[1]['status']);
        $this->assertSame('HTTP 502', $results[1]['detail']);
        $this->assertSame(502, $results[1]['http_code']);
    }

    public function test_no_answer_keeps_the_curl_error_as_the_detail(): void
    {
        $results = AppHealth::parseProbeOutput(
            "3000\thttp\t000 0.000123\tFailed to connect to 127.0.0.1 port 3000\n",
            [3000]
        );

        $this->assertSame(AppHealth::STATUS_FAIL, $results[0]['status']);
        $this->assertNull($results[0]['http_code']);
        $this->assertNull($results[0]['time']);
        $this->assertSame('Failed to connect to 127.0.0.1 port 3000', $results[0]['detail']);
    }

    public function test_no_answer_without_an_error_message_still_reports_a_reason(): void
    {
        $results = AppHealth::parseProbeOutput("3000\thttp\t000 0\t\n", [3000]);

        $this->assertSame('no response', $results[0]['detail']);
    }

    public function test_a_port_the_probe_never_reported_is_a_failure_not_a_gap(): void
    {
        $results = AppHealth::parseProbeOutput("8080\thttp\t200 0.01\t\n", [8080, 9000]);

        $this->assertCount(2, $results);
        $this->assertSame(9000, $results[1]['port']);
        $this->assertSame(AppHealth::STATUS_FAIL, $results[1]['status']);
    }

    public function test_results_follow_the_requested_port_order_not_the_output_order(): void
    {
        $results = AppHealth::parseProbeOutput(
            "9000\thttp\t200 0.01\t\n8080\thttp\t200 0.02\t\n",
            [8080, 9000]
        );

        $this->assertSame([8080, 9000], array_column($results, 'port'));
    }

    public function test_garbage_lines_are_ignored(): void
    {
        $results = AppHealth::parseProbeOutput(
            "docker: something went to stderr\n\n8080\thttp\t200 0.01\t\n",
            [8080]
        );

        $this->assertCount(1, $results);
        $this->assertSame(AppHealth::STATUS_OK, $results[0]['status']);
    }

    /**
     * What reaches `deployment_warnings`, and what deliberately does not.
     *
     * The severity is the whole point: an account still showing its welcome
     * page because nothing has been deployed into it is a warning and belongs
     * in the log. Marking every empty account partial would teach everyone to
     * ignore the field.
     */
    /**
     * chatbot-ui, home-information, mafl: up, answering 500, and the reason
     * only in `docker logs`. A 5xx is what pastes the container output.
     */
    public function test_a_port_answering_5xx_is_a_server_error(): void
    {
        $ports = static fn (array $codes): array => [AppHealth::DETAIL_PORTS => array_map(
            static fn (?int $code): array => ['port' => 3000, 'status' => 'fail', 'http_code' => $code],
            $codes
        )];

        $this->assertTrue(AppHealth::sawServerError($ports([500])));
        $this->assertTrue(AppHealth::sawServerError($ports([200, 502])));
        $this->assertFalse(AppHealth::sawServerError($ports([200, 404])));
        // Nothing answered: not a 5xx, and the restart-loop check covers it.
        $this->assertFalse(AppHealth::sawServerError($ports([null])));
        $this->assertFalse(AppHealth::sawServerError([]));
    }

    public function test_only_error_severity_checks_become_deploy_warnings(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'not-placeholder', 'severity' => 'error', 'message' => 'Serving our own page.'],
                ['id' => 'no-welcome-page', 'severity' => 'warning', 'message' => 'Still the welcome page.'],
                ['id' => 'something', 'severity' => 'info', 'message' => 'Worth knowing.'],
            ],
        ];

        $this->assertSame(['Serving our own page.'], AppHealth::errorCheckMessages($details));
    }

    /**
     * The whole verdict, which is what the three callers actually ask for:
     * the create path, the rebuild path and the account record. Nothing
     * answering comes first -- it explains every check behind it, because a
     * check in that state is describing an error page rather than the
     * application.
     */
    public function test_the_serving_verdict_leads_with_nothing_answering(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 8000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]],
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'no-service-unreachable', 'severity' => 'error', 'message' => 'Nothing answered.'],
                ['id' => 'no-welcome-page', 'severity' => 'warning', 'message' => 'Still the welcome page.'],
            ],
        ];

        $this->assertSame(
            [AppHealth::NOT_ANSWERING, 'Nothing answered.'],
            AppHealth::servingWarnings($details)
        );
    }

    /**
     * The case that used to be asked on the create path alone: for as long as
     * the rebuild path had its own copy of this rule, a redeploy that left an
     * application answering on nothing reported a clean success.
     */
    public function test_an_application_answering_on_no_port_is_a_warning_by_itself(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 3000, 'status' => AppHealth::STATUS_FAIL, 'http_code' => null]],
        ];

        $this->assertSame([AppHealth::NOT_ANSWERING], AppHealth::servingWarnings($details));
    }

    /** A healthy application says nothing at all. */
    public function test_a_serving_application_produces_no_warnings(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => true,
            AppHealth::DETAIL_PORTS => [['port' => 8000, 'status' => AppHealth::STATUS_OK, 'http_code' => 200]],
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'no-welcome-page', 'severity' => 'warning', 'message' => 'Still the welcome page.'],
            ],
        ];

        $this->assertSame([], AppHealth::servingWarnings($details));
    }

    /**
     * engine#81: the app answers 200 inside the account, and visitors get the
     * webserver's 502. That deploy used to end green with one warn line.
     */
    public function test_an_edge_gateway_error_over_an_answering_app_is_a_warning(): void
    {
        $details = fn (string $verdict, ?int $edge, ?int $app = 200): array => [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => $app !== null && $app < 500,
            AppHealth::DETAIL_PORTS => [['port' => 8000, 'status' => $app !== null && $app < 500 ? AppHealth::STATUS_OK : AppHealth::STATUS_FAIL, 'http_code' => $app]],
            AppHealth::DETAIL_REACHABLE => $verdict,
            AppHealth::DETAIL_REACHABLE_CODE => $edge,
        ];

        foreach ([502, 503, 504] as $code) {
            $this->assertSame(
                ["The application answers inside the account, but the site answers {$code} through the webserver."],
                AppHealth::servingWarnings($details(AppHealth::REACH_DIFFERS, $code))
            );
        }
        $this->assertSame(
            ['The application answers inside the account, but the webserver did not accept a connection for the site.'],
            AppHealth::servingWarnings($details(AppHealth::REACH_UNREACHABLE, null))
        );

        // An app that varies by Host differs with an ordinary answer: still only a log line.
        $this->assertSame([], AppHealth::servingWarnings($details(AppHealth::REACH_DIFFERS, 200)));
        $this->assertSame([], AppHealth::servingWarnings($details(AppHealth::REACH_DIFFERS, 404)));
        $this->assertSame([], AppHealth::servingWarnings($details(AppHealth::REACH_OK, 200)));
        // The app's own 5xx is the app's failure, which the checks already report.
        $this->assertSame([], AppHealth::servingWarnings($details(AppHealth::REACH_DIFFERS, 502, 502)));
    }

    public function test_an_account_with_no_check_verdict_contributes_no_warnings(): void
    {
        $this->assertSame([], AppHealth::errorCheckMessages([]));
    }

    /**
     * The sweep reports warnings as well as errors: an application serving
     * the framework's new-project page is worth telling somebody about even
     * though it must not mark a deploy partial.
     */
    public function test_the_sweep_sees_every_failed_check_and_not_only_the_errors(): void
    {
        $details = [
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'not-placeholder', 'severity' => 'error', 'message' => 'Serving our own page.'],
                ['id' => 'no-welcome-page', 'severity' => 'warning', 'message' => 'Still the welcome page.'],
            ],
        ];

        $this->assertSame(
            ['not-placeholder', 'no-welcome-page'],
            array_column(AppHealth::failedChecks($details), 'id')
        );
        $this->assertSame(['Serving our own page.'], AppHealth::errorCheckMessages($details));
    }

    public function test_an_account_never_swept_has_no_failed_checks(): void
    {
        $this->assertSame([], AppHealth::failedChecks([]));
    }

    public function test_summarize_is_true_only_when_every_port_answered(): void
    {
        $ok = ['status' => AppHealth::STATUS_OK];
        $fail = ['status' => AppHealth::STATUS_FAIL];

        $this->assertTrue(AppHealth::summarize([$ok, $ok]));
        $this->assertFalse(AppHealth::summarize([$ok, $fail]));
    }

    public function test_nothing_to_probe_is_not_the_same_as_unhealthy(): void
    {
        $this->assertNull(AppHealth::summarize([]));
    }

    public function test_describe_names_the_target_it_actually_probed(): void
    {
        $ok = AppHealth::describe([
            'port' => 8080, 'scheme' => 'http', 'status' => AppHealth::STATUS_OK,
            'http_code' => 200, 'time' => 0.031, 'detail' => 'HTTP 200',
        ]);
        $fail = AppHealth::describe([
            'port' => 3000, 'scheme' => null, 'status' => AppHealth::STATUS_FAIL,
            'http_code' => null, 'time' => null, 'detail' => 'no response',
        ]);

        $failing = AppHealth::describe([
            'port' => 8081, 'scheme' => 'http', 'status' => AppHealth::STATUS_FAIL,
            'http_code' => 502, 'time' => 0.003, 'detail' => 'HTTP 502',
        ]);

        $this->assertSame('Health check: http://127.0.0.1:8080/ answered HTTP 200 (0.031s)', $ok);
        $this->assertSame('Health check: http://127.0.0.1:3000/ did not answer — no response', $fail);
        // A 5xx answered; it is the app that is broken, not the port.
        $this->assertSame(
            'Health check: http://127.0.0.1:8081/ answered HTTP 502 — the application is failing',
            $failing
        );
    }

    public function test_trim_reason_keeps_the_first_line_and_caps_it(): void
    {
        $oci = "OCI runtime exec failed: exec failed: container_linux.go:439: starting container "
            . "process caused: get handle to /proc/thread-self/fd: unsafe procfs detected: "
            . "openat2 fsmount:fscontext:proc/thread-self/fd/: operation not permitted\nsecond line";

        $trimmed = AppHealth::trimReason($oci);

        $this->assertStringStartsWith('OCI runtime exec failed', $trimmed);
        $this->assertStringNotContainsString('second line', $trimmed);
        $this->assertLessThanOrEqual(160, mb_strlen($trimmed));
        $this->assertStringEndsWith('...', $trimmed);
    }

    public function test_trim_reason_leaves_a_short_message_alone(): void
    {
        $this->assertSame('boom', AppHealth::trimReason("boom\ntrace"));
        $this->assertSame('unknown error', AppHealth::trimReason("\n  \n"));
    }

    public function test_time_budget_covers_both_schemes_on_every_attempt_of_every_port(): void
    {
        $one = AppHealth::timeBudget([8080], 5, 3, 2);
        $two = AppHealth::timeBudget([8080, 8443], 5, 3, 2);

        $this->assertSame(30 + 3 * (2 * 5 + 2), $one);
        $this->assertSame($one + ($one - 30), $two);
    }
    public function test_nothing_answered_only_when_every_probed_port_failed(): void
    {
        // The case that downgrades a deploy: ports published, none serving.
        $this->assertTrue(AppHealth::nothingAnswered([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [
                ['port' => 3000, 'status' => 'fail', 'http_code' => null],
                ['port' => 8080, 'status' => 'fail', 'http_code' => null],
            ],
        ]));

        // One port answering is a recipe worth a log line, not a bad install.
        $this->assertFalse(AppHealth::nothingAnswered([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [
                ['port' => 3000, 'status' => 'fail', 'http_code' => 502],
                ['port' => 8080, 'status' => 'ok', 'http_code' => 200],
            ],
        ]));
    }

    /** A 5xx is an answer: no-server-error describes it, not the silent-port line. */
    public function test_a_port_answering_5xx_has_answered(): void
    {
        $this->assertFalse(AppHealth::nothingAnswered([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [
                ['port' => 3000, 'status' => 'fail', 'http_code' => 502],
                ['port' => 8080, 'status' => 'fail', 'http_code' => null],
            ],
        ]));

        $details = [
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [['port' => 8080, 'status' => 'fail', 'http_code' => 500]],
            AppHealth::DETAIL_CHECKS => [
                ['id' => 'no-server-error', 'severity' => 'error', 'message' => 'The site returned 500 instead of a page.'],
            ],
        ];
        $this->assertSame(['The site returned 500 instead of a page.'], AppHealth::servingWarnings($details));
    }

    public function test_a_worker_publishing_no_port_is_never_called_unhealthy(): void
    {
        // A queue consumer answers nothing on HTTP and is a correct deploy.
        $this->assertFalse(AppHealth::nothingAnswered([
            AppHealth::DETAIL_CHECKED => false,
            AppHealth::DETAIL_HEALTHY => null,
            AppHealth::DETAIL_PORTS => [],
        ]));
    }

    public function test_a_probe_that_could_not_run_does_not_condemn_the_deploy(): void
    {
        // check() reports healthy:false with no ports when it could not ask at
        // all -- a wedged container. Not knowing is not the same as knowing
        // the app is down, so it must not downgrade anything.
        $this->assertFalse(AppHealth::nothingAnswered([
            AppHealth::DETAIL_CHECKED => true,
            AppHealth::DETAIL_HEALTHY => false,
            AppHealth::DETAIL_PORTS => [],
        ]));
    }

    public function test_an_account_that_was_never_probed_is_not_unhealthy(): void
    {
        $this->assertFalse(AppHealth::nothingAnswered([]));
        $this->assertFalse(AppHealth::nothingAnswered(['deploy_strategy' => 'nextjs']));
    }

    private static function fingerprint(int $code, string $hash, bool $default404 = false): array
    {
        return ['code' => $code, 'hash' => $hash, 'default404' => $default404];
    }

    public function test_the_edge_probe_carries_the_name_without_needing_dns(): void
    {
        $script = AppHealth::edgeProbeScript('shop.example.com', '203.0.113.10', 4);

        // The Host header is the name; the address is the one the vhost binds.
        // A domain whose DNS is not pointed here yet still gets checked.
        $this->assertStringContainsString("host='shop.example.com'", $script);
        $this->assertStringContainsString("addr='203.0.113.10'", $script);
        $this->assertStringContainsString('-H "Host: $host" "http://$addr/"', $script);
        $this->assertStringContainsString('-m 4', $script);
    }

    public function test_a_domain_serving_the_application_is_reachable(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'shop.example.com',
            self::fingerprint(200, 'abc'),
            self::fingerprint(200, 'abc')
        );

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertSame(200, $verdict['http_code']);
    }

    /**
     * The outage this check exists for: both ends answered 200, and the domain
     * was serving another project entirely. Only the bytes tell them apart.
     */
    public function test_two_matching_status_codes_are_not_enough(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'shop.example.com',
            self::fingerprint(200, 'someone-elses-site'),
            self::fingerprint(200, 'this-app')
        );

        $this->assertSame(AppHealth::REACH_DIFFERS, $verdict['verdict']);
    }

    public function test_the_webservers_own_404_reads_as_an_unloaded_vhost(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'shop.example.com',
            self::fingerprint(404, 'engine-404', true),
            self::fingerprint(200, 'this-app')
        );

        $this->assertSame(AppHealth::REACH_NOT_ROUTED, $verdict['verdict']);
        $this->assertStringContainsString('no vhost is loaded', $verdict['detail']);
    }

    public function test_nothing_answering_reads_as_unreachable(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'shop.example.com',
            self::fingerprint(0, ''),
            self::fingerprint(200, 'this-app')
        );

        $this->assertSame(AppHealth::REACH_UNREACHABLE, $verdict['verdict']);
        $this->assertNull($verdict['http_code']);
    }

    public function test_a_fingerprint_line_is_parsed_into_code_hash_and_marker(): void
    {
        $this->assertSame(
            ['code' => 404, 'hash' => 'deadbeef', 'default404' => true, 'hash2' => '', 'location' => '', 'scheme' => ''],
            AppHealth::parseFingerprint("404\tdeadbeef\tyes")
        );
    }

    /**
     * WordPress on every site: probed on 127.0.0.1 it redirects to the address
     * it was installed under, and through its domain it serves the page. The
     * bodies differ, and nothing is wrong.
     */
    public function test_an_application_that_canonicalises_locally_is_still_reachable(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'shop.panelalpha.online',
            self::fingerprint(200, 'the-real-page'),
            self::fingerprint(301, 'a-redirect-to-siteurl')
        );

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertStringContainsString('redirects to its own address', $verdict['detail']);
    }

    /**
     * OpenCloud: the vhost forwards plain http as `X-Forwarded-Proto: http`
     * and the app upgrades it with a 308, while the local probe gets 200.
     */
    public function test_an_edge_redirect_to_https_on_the_same_address_is_reachable(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'cloud.panelalpha.online',
            self::fingerprint(308, 'a-redirect') + ['location' => 'https://Cloud.panelalpha.online/'],
            self::fingerprint(200, 'the-real-page')
        );

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertSame(308, $verdict['http_code']);
        $this->assertStringContainsString('https://cloud.panelalpha.online/', $verdict['detail']);
    }

    /** @return array<string, array{0: string}> */
    public static function redirectsElsewhere(): array
    {
        return [
            'another host' => ['https://someone-else.example.com/'],
            'plain http' => ['http://cloud.panelalpha.online/'],
            'another path' => ['https://cloud.panelalpha.online/login'],
            'another port' => ['https://cloud.panelalpha.online:8443/'],
            'no location' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redirectsElsewhere')]
    public function test_an_edge_redirect_anywhere_else_still_differs(string $location): void
    {
        $verdict = AppHealth::compareFingerprints(
            'cloud.panelalpha.online',
            self::fingerprint(308, 'a-redirect') + ['location' => $location],
            self::fingerprint(200, 'the-real-page')
        );

        $this->assertSame(AppHealth::REACH_DIFFERS, $verdict['verdict']);
    }

    public function test_the_edge_probe_reports_where_a_redirect_points(): void
    {
        $dir = sys_get_temp_dir() . '/pa-edge-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // A fake curl answering the way OpenCloud's vhost does over plain http.
        file_put_contents($dir . '/curl', "#!/bin/sh\nprintf '308 https://cloud.panelalpha.online/'\n");
        chmod($dir . '/curl', 0o755);
        file_put_contents($dir . '/edge.sh', AppHealth::edgeProbeScript('cloud.panelalpha.online', '203.0.113.10'));
        $raw = (string) shell_exec('PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($dir . '/edge.sh'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        $edge = AppHealth::parseFingerprint($raw);
        $this->assertSame(308, $edge['code']);
        $this->assertSame('', $edge['hash2']);
        $this->assertSame('https://cloud.panelalpha.online/', $edge['location']);
    }

    public function test_two_answering_applications_that_differ_are_still_flagged(): void
    {
        // The exemption is for a local redirect, not for any difference: both
        // ends answering 200 with different bodies is the outage this catches.
        $verdict = AppHealth::compareFingerprints(
            'shop.panelalpha.online',
            self::fingerprint(200, 'someone-elses-site'),
            self::fingerprint(200, 'this-app')
        );

        $this->assertSame(AppHealth::REACH_DIFFERS, $verdict['verdict']);
    }

    /**
     * The application probe fetches twice, so the second hash rides along.
     */
    public function test_a_fingerprint_line_carries_the_second_hash_when_there_is_one(): void
    {
        $this->assertSame(
            ['code' => 200, 'hash' => 'aaa', 'default404' => false, 'hash2' => 'bbb', 'location' => '', 'scheme' => ''],
            AppHealth::parseFingerprint("200\taaa\tno\tbbb")
        );
    }

    /**
     * Nextcloud and ownCloud stamp a fresh CSRF token into every response, so
     * two identical local requests already differ. Hashing one and comparing
     * it to the edge reported healthy sites as unreachable -- observed on both
     * on a live host, each answering a plain 200 at the origin.
     *
     * When the application cannot reproduce its own bytes the body is not
     * evidence, so only the status is compared.
     */
    public function test_an_application_that_varies_its_own_body_is_still_reachable(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'cloud.example.com',
            self::fingerprint(200, 'edge-token-a'),
            self::fingerprint(200, 'local-token-b') + ['hash2' => 'local-token-c'],
        );

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertStringContainsString('varies its own response', $verdict['detail']);
    }

    /**
     * The relaxation is only about the body. A varying application whose edge
     * answers a different status is still wrong.
     */
    public function test_a_varying_application_is_still_flagged_when_the_status_differs(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'cloud.example.com',
            self::fingerprint(502, 'gateway-error'),
            self::fingerprint(200, 'local-token-b') + ['hash2' => 'local-token-c'],
        );

        $this->assertSame(AppHealth::REACH_DIFFERS, $verdict['verdict']);
    }

    /**
     * An application that *does* reproduce its bytes keeps the strict check --
     * that is what catches a domain serving somebody else's site.
     */
    public function test_a_stable_application_is_still_compared_by_body(): void
    {
        $verdict = AppHealth::compareFingerprints(
            'shop.example.com',
            self::fingerprint(200, 'someone-elses-site'),
            self::fingerprint(200, 'this-app') + ['hash2' => 'this-app'],
        );

        $this->assertSame(AppHealth::REACH_DIFFERS, $verdict['verdict']);
    }

    /**
     * Drive awaitRoute() with scripted edge answers; records every sleep.
     *
     * @param list<array{code: int, hash: string, default404: bool}> $edges
     * @param list<int>|null $slept
     * @param int|null $probes
     */
    private static function awaitRoute(array $edges, &$slept, &$probes): array
    {
        $probes = 0;
        $slept = [];
        $last = end($edges);

        return AppHealth::awaitRoute(
            'shop.example.com',
            function () use (&$probes, $edges, $last): array {
                return $edges[$probes++] ?? $last;
            },
            self::fingerprint(200, 'this-app'),
            function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            }
        );
    }

    /** The vhost reload had not landed on the first probe; the retry sees it. */
    public function test_an_unrouted_edge_is_re_probed_until_the_vhost_loads(): void
    {
        $verdict = self::awaitRoute([
            self::fingerprint(404, 'engine-404', true),
            self::fingerprint(200, 'this-app'),
        ], $slept, $probes);

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertSame(2, $probes);
        $this->assertSame([1], $slept);
    }

    public function test_an_edge_that_stays_unrouted_is_still_reported_after_the_retries(): void
    {
        $verdict = self::awaitRoute([self::fingerprint(404, 'engine-404', true)], $slept, $probes);

        $this->assertSame(AppHealth::REACH_NOT_ROUTED, $verdict['verdict']);
        $this->assertSame(AppHealth::EDGE_RETRY_DELAYS, $slept);
        $this->assertSame(1 + count(AppHealth::EDGE_RETRY_DELAYS), $probes);
        $this->assertSame(30, array_sum($slept));
    }

    public function test_an_unreachable_edge_is_retried_too(): void
    {
        $verdict = self::awaitRoute([
            self::fingerprint(0, ''),
            self::fingerprint(0, ''),
            self::fingerprint(200, 'this-app'),
        ], $slept, $probes);

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertSame([1, 2], $slept);
    }

    public function test_a_reachable_edge_is_not_probed_twice(): void
    {
        $verdict = self::awaitRoute([self::fingerprint(200, 'this-app')], $slept, $probes);

        $this->assertSame(AppHealth::REACH_OK, $verdict['verdict']);
        $this->assertSame(1, $probes);
        $this->assertSame([], $slept);
    }

    /** A different answer is not a reload race, so it is not waited on. */
    public function test_a_differing_edge_returns_without_retrying(): void
    {
        $verdict = self::awaitRoute([self::fingerprint(200, 'someone-elses-site')], $slept, $probes);

        $this->assertSame(AppHealth::REACH_DIFFERS, $verdict['verdict']);
        $this->assertSame(1, $probes);
        $this->assertSame([], $slept);
    }

    /** An app that writes the request Host into its page must see the same Host at both ends. */
    public function test_the_app_probe_sends_the_domain_as_host(): void
    {
        $script = AppHealth::appProbeScript('http', 8080, 4, 'shop.example.com');

        $this->assertSame(2, substr_count($script, "-H 'Host: shop.example.com'"));
        $this->assertStringContainsString("'http://127.0.0.1:8080/'", $script);
    }

    /** Immich Kiosk stamps the current second into its page. */
    public function test_the_app_probe_sees_a_per_second_timestamp_as_varying(): void
    {
        $dir = sys_get_temp_dir() . '/pa-app-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/curl', <<<'SH'
            #!/bin/sh
            while [ $# -gt 0 ]; do [ "$1" = "-o" ] && out=$2; shift; done
            date +%s >"$out"
            printf 200
            SH);
        chmod($dir . '/curl', 0o755);
        file_put_contents($dir . '/app.sh', AppHealth::appProbeScript('http', 3000, 2));
        $raw = (string) shell_exec('PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($dir . '/app.sh'));
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        $app = AppHealth::parseFingerprint($raw);
        $this->assertSame(200, $app['code']);
        $this->assertNotSame($app['hash'], $app['hash2'], 'the two fetches must not share a second');
    }

    public function test_the_app_probe_sends_no_host_without_a_domain(): void
    {
        $this->assertStringNotContainsString('Host:', AppHealth::appProbeScript('http', 8080, 4));
        $this->assertStringNotContainsString('Host:', AppHealth::appProbeScript('http', 8080, 4, ''));
    }

    /**
     * An app that needs the forwarded headers (403 without X-Forwarded-For,
     * 500 unless X-Forwarded-Proto is https) answered the reachability probe
     * differently from every visitor, and the deploy said "Not reachable".
     * Both fetches send what the vhost forwards for a visitor on https.
     */
    public function test_the_app_probe_sends_what_the_vhost_forwards_for_a_visitor(): void
    {
        $script = AppHealth::appProbeScript('http', 8080, 4, 'shop.example.com');

        foreach (["X-Forwarded-Proto: https", "X-Forwarded-Host: shop.example.com", 'X-Forwarded-Port: 443', 'X-Forwarded-For: ', 'X-Real-IP: '] as $header) {
            $this->assertSame(2, substr_count($script, "-H '{$header}"), $header);
        }
    }

    /** @return array{0: string, 1: string} the fingerprint line, and every curl call */
    private static function runEdgeProbe(bool $https, string $httpsAnswer): array
    {
        $dir = sys_get_temp_dir() . '/pa-edge-' . bin2hex(random_bytes(4));
        mkdir($dir);
        // https answers $httpsAnswer, plain http answers 500.
        file_put_contents($dir . '/curl', <<<SH
            #!/bin/sh
            echo "\$*" >> {$dir}/calls
            case "\$*" in *https://*) printf '{$httpsAnswer} ' ;; *) printf '500 ' ;; esac
            SH);
        chmod($dir . '/curl', 0o755);
        file_put_contents($dir . '/edge.sh', AppHealth::edgeProbeScript('shop.example.com', '203.0.113.10', 4, $https));
        $raw = (string) shell_exec('PATH=' . escapeshellarg($dir . ':' . getenv('PATH')) . ' bash ' . escapeshellarg($dir . '/edge.sh'));
        $calls = (string) @file_get_contents($dir . '/calls');
        array_map('unlink', glob($dir . '/*') ?: []);
        rmdir($dir);

        return [$raw, $calls];
    }

    public function test_the_edge_is_probed_over_https_when_the_domain_has_tls(): void
    {
        [$raw, $calls] = self::runEdgeProbe(true, '200');

        $edge = AppHealth::parseFingerprint($raw);
        $this->assertSame(200, $edge['code']);
        $this->assertSame('https', $edge['scheme']);
        // SNI and Host both carry the name; the address is still the vhost's.
        $this->assertStringContainsString('--resolve shop.example.com:443:203.0.113.10 https://shop.example.com/', $calls);
        $this->assertStringNotContainsString('http://203.0.113.10/', $calls);

        $verdict = AppHealth::compareFingerprints('shop.example.com', $edge, self::fingerprint(200, $edge['hash']));
        $this->assertSame('Reachable: https://shop.example.com/ answered 200 through the webserver', AppHealth::describeReach($verdict));
    }

    public function test_the_edge_falls_back_to_http_when_https_answers_nothing(): void
    {
        [$raw] = self::runEdgeProbe(true, '000');

        $edge = AppHealth::parseFingerprint($raw);
        $this->assertSame(500, $edge['code']);
        $this->assertSame('http', $edge['scheme']);
    }

    public function test_the_edge_of_a_domain_without_tls_is_probed_over_http(): void
    {
        [$raw, $calls] = self::runEdgeProbe(false, '200');

        $this->assertSame('http', AppHealth::parseFingerprint($raw)['scheme']);
        $this->assertStringNotContainsString('https://', $calls);
        $verdict = AppHealth::compareFingerprints('shop.example.com', AppHealth::parseFingerprint($raw), self::fingerprint(200, 'x'));
        $this->assertStringStartsWith('Not reachable: http://shop.example.com/ answered 500', AppHealth::describeReach($verdict));
    }
}
