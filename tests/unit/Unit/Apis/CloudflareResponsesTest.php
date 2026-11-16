<?php

namespace Tests\Unit\Apis;

use App\Lib\Apis\Cloudflare;
use App\Lib\Apis\Cloudflare\CloudflareException;
use App\Lib\Apis\Cloudflare\ZoneNotFoundException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The DNS CNAME upsert, the zone and account picks, and every shape of a failed Cloudflare answer. */
class CloudflareResponsesTest extends TestCase
{
    private const TARGET = 'tun-1.cfargotunnel.com';

    private const RECORD = ['type' => 'CNAME', 'name' => 'app.example.test', 'content' => self::TARGET, 'proxied' => true, 'ttl' => 1];

    public function test_a_proxied_cname_to_the_tunnel_is_kept_without_a_write(): void
    {
        $this->fakeDns([
            ['name' => 'skipped.example.test'],
            ['id' => 'rec-1', 'type' => 'cname', 'name' => 'App.example.test', 'content' => 'TUN-1.cfargotunnel.com', 'proxied' => 1],
        ]);

        $record = $this->client()->ensureDnsCname('zone-id', 'app.example.test', 'tun-1');

        $this->assertSame(['id' => 'rec-1', 'name' => 'App.example.test', 'content' => self::TARGET], $record);
        Http::assertSentCount(1);
        $this->assertStringContainsString('/zones/zone-id/dns_records?name=app.example.test&per_page=50', Http::recorded()[0][0]->url());
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function recordsThatAreNotTheTunnel(): array
    {
        return [
            'not proxied' => [['id' => 'rec-1', 'type' => 'CNAME', 'content' => self::TARGET, 'proxied' => false]],
            'an A record' => [['id' => 'rec-1', 'type' => 'A', 'content' => self::TARGET, 'proxied' => true]],
            'another target' => [['id' => 'rec-1', 'type' => 'CNAME', 'content' => 'tun-2.cfargotunnel.com', 'proxied' => true]],
        ];
    }

    /** @param array<string, mixed> $existing */
    #[DataProvider('recordsThatAreNotTheTunnel')]
    public function test_a_record_that_is_not_the_tunnel_is_replaced_in_place(array $existing): void
    {
        $this->fakeDns([$existing], Http::response(['success' => true, 'result' => ['id' => 'rec-1', 'name' => 'app.example.test']]));

        $record = $this->client()->ensureDnsCname('zone-id', 'App.example.test', 'tun-1');

        $this->assertSame(['id' => 'rec-1', 'name' => 'app.example.test', 'content' => self::TARGET], $record);
        $write = $this->lastWrite();
        $this->assertSame('PUT', $write->method());
        $this->assertStringEndsWith('/zones/zone-id/dns_records/rec-1', $write->url());
        $this->assertSame(array_replace(self::RECORD, ['name' => 'App.example.test']), $write->data());
    }

    public function test_a_missing_record_is_created_and_named_after_the_hostname(): void
    {
        $this->fakeDns([], Http::response(['success' => true, 'result' => ['id' => 'rec-9']]));

        $record = $this->client()->ensureDnsCname('zone-id', 'New.example.test', 'tun-1');

        $this->assertSame(['id' => 'rec-9', 'name' => 'New.example.test', 'content' => self::TARGET], $record);
        $write = $this->lastWrite();
        $this->assertSame('POST', $write->method());
        $this->assertStringEndsWith('/zones/zone-id/dns_records', $write->url());
        $this->assertSame(array_replace(self::RECORD, ['name' => 'New.example.test']), $write->data());
    }

    /** @return array<string, array{mixed}> */
    public static function writesWithoutAnId(): array
    {
        return [
            'no id' => [['name' => 'app.example.test']],
            'empty id' => [['id' => '']],
            'not a record' => ['rec-1'],
        ];
    }

    #[DataProvider('writesWithoutAnId')]
    public function test_a_write_answered_without_an_id_is_a_failure(mixed $result): void
    {
        $this->fakeDns('not-a-list', Http::response(['success' => true, 'result' => $result]));

        $e = $this->failureOf(fn () => $this->client()->ensureDnsCname('zone-id', 'app.example.test', 'tun-1'));

        $this->assertSame("Failed to upsert DNS CNAME for 'app.example.test'.", $e->getMessage());
        $this->assertNull($e->responseStatus());
        $this->assertSame('POST', $this->lastWrite()->method());
    }

    public function test_the_longest_matching_zone_wins_and_the_first_of_equal_length(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => [
            'junk',
            ['name' => 'Example.TEST', 'id' => 'z1'],
            ['name' => '', 'id' => 'z2'],
            ['name' => 'shop.example.test', 'id' => ''],
            ['name' => 'example.test', 'id' => 'z3'],
            ['name' => 'op.example.test', 'id' => 'z4'],
        ]])]);

        $this->assertSame(['id' => 'z1', 'name' => 'example.test'], $this->client()->findZoneForHostname(' Shop.Example.test '));
        $this->assertSame(['id' => 'z1', 'name' => 'example.test'], $this->client()->findZoneForHostname('EXAMPLE.test'));
    }

    /** @return array<string, array{mixed}> */
    public static function noVisibleZones(): array
    {
        return [
            'empty' => [[]],
            'not a list' => ['zones'],
            'absent' => [null],
        ];
    }

    #[DataProvider('noVisibleZones')]
    public function test_no_visible_zone_names_all_three_causes(mixed $result): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => $result])]);

        $e = $this->failureOf(fn () => $this->client()->findZoneForHostname('shop.example.test'));

        $this->assertInstanceOf(ZoneNotFoundException::class, $e);
        $this->assertSame(
            "Hostname 'shop.example.test' is not under any Cloudflare zone this API token can read. Check that it has "
            .'Zone > Zone > Read, that Zone Resources includes the zone this hostname is under, and that the zone '
            .'belongs to the account the token selects.',
            $e->getMessage()
        );
    }

    public function test_a_hostname_under_no_zone_is_named_lowercased(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => [['name' => 'example.test', 'id' => 'z1']]])]);

        $e = $this->failureOf(fn () => $this->client()->findZoneForHostname(' Shop.Other.TEST'));

        $this->assertInstanceOf(ZoneNotFoundException::class, $e);
        $this->assertSame(
            "Hostname 'shop.other.test' is not under any Cloudflare zone this API token can read. Check that it has "
            .'Zone > Zone > Read, that Zone Resources includes the zone this hostname is under, and that the zone '
            .'belongs to the account the token selects.',
            $e->getMessage()
        );
    }

    public function test_the_zone_route_takes_the_last_name_seen_for_an_account(): void
    {
        Http::fake([
            '*/accounts?*' => Http::response(['success' => true, 'result' => [['name' => 'no id']]]),
            '*/zones?*' => Http::response(['success' => true, 'result' => [
                'junk',
                ['account' => 'acc-0'],
                ['account' => ['name' => 'No id']],
                ['account' => ['id' => 'acc-1', 'name' => 'First']],
                ['account' => ['id' => 'acc-1']],
            ]]),
        ]);

        $this->assertSame(['id' => 'acc-1', 'name' => ''], $this->client()->resolveAccount());
    }

    public function test_zones_in_several_accounts_are_named_with_and_without_a_name(): void
    {
        Http::fake([
            '*/accounts?*' => Http::response(['success' => true, 'result' => []]),
            '*/zones?*' => Http::response(['success' => true, 'result' => [
                ['account' => ['id' => 'acc-1', 'name' => 'Shop']],
                ['account' => ['id' => 'acc-2']],
            ]]),
        ]);

        $e = $this->failureOf(fn () => $this->client()->resolveAccount());

        $this->assertSame(
            'This API token can see zones in more than one Cloudflare account (Shop [acc-1], acc-2), so the engine '
            .'cannot tell which one the tunnel belongs in. Give the token Account Resources for the account you want, '
            .'so /accounts answers directly, or scope Zone Resources to zones in that one account.',
            $e->getMessage()
        );
        $this->assertNull($e->getPrevious());
    }

    public function test_an_account_list_naming_several_accounts_gets_the_zone_routes_refusal(): void
    {
        Http::fake([
            '*/accounts?*' => Http::response(['success' => true, 'result' => [
                ['id' => 'acc-1', 'name' => 'Shop'],
                ['id' => 'acc-2'],
            ]]),
            '*/zones?*' => Http::response(['success' => true, 'result' => [
                ['account' => ['id' => 'acc-1', 'name' => 'Shop']],
            ]]),
        ]);

        $e = $this->failureOf(fn () => $this->client()->resolveAccount());

        $this->assertSame(
            'This API token can see zones in more than one Cloudflare account (Shop [acc-1], acc-2), so the engine '
            .'cannot tell which one the tunnel belongs in. Give the token Account Resources for the account you want, '
            .'so /accounts answers directly, or scope Zone Resources to zones in that one account.',
            $e->getMessage()
        );
        Http::assertSentCount(1);
    }

    public function test_the_first_refusal_is_the_one_reported(): void
    {
        Http::fake([
            '*/accounts?*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'First refusal']]], 403),
            '*/zones?*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Second refusal']]], 403),
        ]);

        $e = $this->failureOf(fn () => $this->client()->resolveAccount());

        $this->assertSame('Cloudflare rejected this API token: Cloudflare API request failed: First refusal', $e->getMessage());
        $this->assertInstanceOf(CloudflareException::class, $e->getPrevious());
        $this->assertNull($e->responseStatus());
    }

    public function test_a_token_that_sees_nothing_is_told_what_to_check(): void
    {
        Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);

        $e = $this->failureOf(fn () => $this->client()->resolveAccount());

        $this->assertSame(
            'Cloudflare API token has no accessible accounts. Check that it selects an account under Account Resources, '
            .'and that Zone Resources includes at least one zone in that account -- the engine can take the account id from either.',
            $e->getMessage()
        );
        $this->assertNull($e->getPrevious());
    }

    /** @return array<string, array{mixed, int, string, ?int}> */
    public static function failedAnswers(): array
    {
        return [
            'an error status without a body' => ['', 500, 'Cloudflare API request failed (HTTP 500)', 500],
            'an error status with no errors' => [['errors' => []], 400, 'Cloudflare API request failed (HTTP 400)', 400],
            'an error status with errors that are not a list' => [['errors' => 'nope'], 400, 'Cloudflare API request failed (HTTP 400)', 400],
            'an error status with errors carrying no message' => [['errors' => [['code' => 1]]], 400, 'Cloudflare API request failed', 400],
            'an error status with messages' => [
                ['errors' => [['code' => 9109, 'message' => 'One'], 'junk', ['message' => 2]]],
                403,
                'Cloudflare API request failed: One; 2',
                403,
            ],
            'a body that is not JSON' => ['<html>', 200, 'Cloudflare API returned a non-JSON response.', null],
            'success false with messages' => [
                ['success' => false, 'errors' => [['message' => 'One'], ['code' => 7], ['message' => 'Two']]],
                200,
                'Cloudflare API error: One; Two',
                200,
            ],
            'success false without errors' => [['success' => false], 200, 'Cloudflare API error', 200],
            'success false with errors that are not a list' => [['success' => false, 'errors' => 'nope'], 200, 'Cloudflare API error', 200],
        ];
    }

    #[DataProvider('failedAnswers')]
    public function test_each_failed_answer_has_its_own_message(mixed $body, int $httpStatus, string $message, ?int $status): void
    {
        Http::fake(['*' => Http::response($body, $httpStatus)]);

        $e = $this->failureOf(fn () => $this->client()->purgeCache('zone-id', true));

        $this->assertSame(CloudflareException::class, $e::class);
        $this->assertSame($message, $e->getMessage());
        $this->assertSame($status, $e->responseStatus());
        $this->assertSame($status !== null && $status >= 400, $e->getPrevious() instanceof RequestException);
    }

    /** @return array<string, array{int}> */
    public static function statuses(): array
    {
        return ['an error status' => [400], 'success false' => [200]];
    }

    #[DataProvider('statuses')]
    public function test_an_error_chain_is_read_on_either_kind_of_failure(int $status): void
    {
        $error = ['code' => 6003, 'message' => 'Invalid request headers', 'error_chain' => [['code' => 6111, 'message' => 'Invalid format']]];
        Http::fake(['*' => Http::response(['success' => false, 'errors' => [$error]], $status)]);

        $e = $this->failureOf(fn () => $this->client()->purgeCache('zone-id', true));

        $this->assertTrue($e->isInvalidToken());
        $this->assertStringEndsWith('Invalid request headers', $e->getMessage());
    }

    public function test_an_answer_without_success_is_taken_as_success(): void
    {
        Http::fake(['*' => Http::response(['result' => ' tok '])]);

        $this->assertSame('tok', $this->client()->getTunnelToken('acc', 'tun'));
    }

    private function client(): Cloudflare
    {
        return new Cloudflare(' cf-token ');
    }

    private function fakeDns(mixed $records, ?PromiseInterface $write = null): void
    {
        Http::fake(function (Request $request) use ($records, $write): PromiseInterface {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $records]);
            }

            return $write ?? Http::response(['success' => false], 500);
        });
    }

    private function lastWrite(): Request
    {
        $writes = array_values(array_filter(
            array_map(static fn (array $pair): Request => $pair[0], Http::recorded()->all()),
            static fn (Request $request): bool => $request->method() !== 'GET'
        ));
        $this->assertCount(1, $writes);

        return $writes[0];
    }

    private function failureOf(callable $call): CloudflareException
    {
        try {
            $call();
        } catch (CloudflareException $e) {
            return $e;
        }

        $this->fail('expected a CloudflareException');
    }
}
