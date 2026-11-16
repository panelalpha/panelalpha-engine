<?php

namespace Tests\Unit\System\Webserver;

use App\Models\Setting;
use App\System\Services\Webserver\Litespeed;
use App\System\Services\Webserver\Openlitespeed;
use Tests\Support\InMemoryDatabase;
use Tests\Support\LocalRoot;
use Tests\Support\TestNetwork;
use Tests\TestCase;

/**
 * The LiteSpeed and OpenLiteSpeed drivers against an in-memory host: a deleted domain leaves
 * httpd_config, serial backups rotate by timestamp, and OpenLiteSpeed neither takes nor reports a serial number.
 */
class LiteSpeedDriversTest extends TestCase
{
    use InMemoryDatabase;

    private const LS_DIR = '/opt/engine/webserver-config/litespeed';

    private const LS_CONFIG = self::LS_DIR.'/httpd_config.xml';

    private const OLS_CONFIG = '/opt/engine/webserver-config/openlitespeed/httpd_config.conf';

    private WebserverHostDouble $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
        Setting::setRuntimeSettings(['default_ipv4' => TestNetwork::DOCUMENTATION_2]);
        config(['env.APP_LITE_PROXY_ENABLED' => false]);
        $this->host = new WebserverHostDouble(dirname(base_path()).'/templates');
        $this->makeMainDomain($this->makeUser('alice', [], 'alice.test'), 'alice.test');
        $this->makeMainDomain($this->makeUser('bob', [], 'bob.test'), 'bob.test');
        $this->host->files[self::LS_CONFIG] = $this->litespeedConfig('');
        $this->host->files[self::OLS_CONFIG] = "serverName engine\n";
        putenv('PA_ROOT_LOCAL_ROOTS={"engine":"/opt/engine"}');
    }

    protected function tearDown(): void
    {
        putenv('PA_ROOT_LOCAL_ROOTS');
        Setting::clearRuntimeSettings();
        parent::tearDown();
    }

    public function test_litespeed_drops_a_deleted_domain_from_httpd_config(): void
    {
        (new Litespeed($this->host))->deleteDomainConfig('bob.test');

        $this->assertStringContainsString('alice.test', $this->host->files[self::LS_CONFIG]);
        $this->assertStringNotContainsString('bob.test', $this->host->files[self::LS_CONFIG]);
    }

    public function test_openlitespeed_drops_a_deleted_domain_from_httpd_config(): void
    {
        (new Openlitespeed($this->host))->deleteDomainConfig('bob.test');

        $this->assertStringContainsString('alice.test', $this->host->files[self::OLS_CONFIG]);
        $this->assertStringNotContainsString('bob.test', $this->host->files[self::OLS_CONFIG]);
    }

    public function test_litespeed_keeps_the_ten_newest_serial_backups_and_every_key_backup(): void
    {
        $this->host->files[self::LS_DIR.'/serial.no'] = 'OLD';
        $this->host->files[self::LS_DIR.'/license.key'] = 'LICENSE';
        $this->host->files[self::LS_DIR.'/serial.no.bak999999999'] = 'old';
        for ($stamp = 1700000000; $stamp < 1700000011; $stamp++) {
            $this->host->files[self::LS_DIR."/serial.no.bak{$stamp}"] = 'old';
            $this->host->files[self::LS_DIR."/license.key.bak{$stamp}"] = 'old';
            $this->host->files[self::LS_DIR."/trial.key.bak{$stamp}"] = 'old';
        }

        (new Litespeed($this->host))->updateConfig('serial_number', 'NEW-SERIAL');

        $this->assertEqualsCanonicalizing([
            self::LS_DIR.'/serial.no.bak999999999',
            self::LS_DIR.'/serial.no.bak1700000000',
            self::LS_DIR.'/serial.no.bak1700000001',
        ], $this->removedPaths());
    }

    public function test_litespeed_modsecurity_toggle_removes_every_censorship_node(): void
    {
        $this->host->files[self::LS_CONFIG] = $this->litespeedConfig(
            '<censorshipControl><enableCensorship>0</enableCensorship></censorshipControl>'
            .'<censorshipControl><enableCensorship>0</enableCensorship></censorshipControl>'
        );

        (new Litespeed($this->host))->toggleModsecurity();

        $this->assertSame(1, $this->countInLitespeedConfig('censorshipControl'));
        $this->assertSame(1, $this->countInLitespeedConfig('censorshipRuleSet'));
    }

    public function test_litespeed_modsecurity_toggle_removes_a_censorship_node_below_security(): void
    {
        $this->host->files[self::LS_CONFIG] = $this->litespeedConfig(
            '<ruleGroup><censorshipRuleSet><name>Old</name></censorshipRuleSet></ruleGroup>'
        );

        (new Litespeed($this->host))->toggleModsecurity();

        $this->assertSame(1, $this->countInLitespeedConfig('censorshipRuleSet'));
    }

    public function test_openlitespeed_refuses_a_serial_number(): void
    {
        $refusal = null;
        try {
            (new Openlitespeed($this->host))->updateConfig('serial_number', 'NEW-SERIAL');
        } catch (\Exception $e) {
            $refusal = $e->getMessage();
        }

        $this->assertSame('OpenLiteSpeed has no serial number to update', $refusal);
        $this->assertSame([], $this->host->journal);
    }

    public function test_openlitespeed_details_report_no_serial_number(): void
    {
        $this->host->files['/opt/engine/webserver-config/openlitespeed/serial.no'] = 'LEFT-BY-AN-OLD-ENGINE';

        $details = (new Openlitespeed($this->host))->getDetails();

        $this->assertSame(['web_panel_port' => '7080', 'web_panel_username' => 'admin'], $details['metadata']);
    }

    /** @return list<string> */
    private function removedPaths(): array
    {
        $removed = [];
        foreach ($this->host->journal as $line) {
            $argv = str_starts_with($line, 'run [') ? json_decode(substr($line, 4), true) : [];
            if (LocalRoot::isRequest($argv) && (LocalRoot::plain($argv)[0] ?? '') === 'rm') {
                $removed[] = (string) end($argv);
            }
        }

        return $removed;
    }

    private function countInLitespeedConfig(string $tag): int
    {
        $dom = new \DOMDocument;
        $dom->loadXML($this->host->files[self::LS_CONFIG]);

        return $dom->getElementsByTagName($tag)->length;
    }

    private function litespeedConfig(string $security): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            ."<httpServerConfig><security>{$security}</security>"
            .'<listenerList></listenerList><virtualHostList></virtualHostList></httpServerConfig>'."\n";
    }
}
