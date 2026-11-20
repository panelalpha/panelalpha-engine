<?php

namespace Tests\Unit\System\Privileged;

use App\System\Privileged;
use App\System\Privileged\ActionValues;
use App\System\Privileged\Arguments;
use App\System\Privileged\HostValues;
use App\System\Privileged\InvalidArgument;
use App\System\Privileged\Root;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/** Requests the broker would refuse are refused while they are built, before anything is asked of root. */
class PrivilegedRefusalsTest extends TestCase
{
    public function test_a_negative_account_id_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::accountFileManager(-1, 1000)->exists('/home/acme/project');
    }

    public function test_a_pair_needs_a_count_where_it_goes(): void
    {
        $this->expectException(InvalidArgument::class);

        ActionValues::withPairInFront(['image', 'two'], 1, ['label', 'value']);
    }

    public function test_a_registry_repository_must_be_a_lowercase_path(): void
    {
        $this->expectException(InvalidArgument::class);

        Arguments::registryRepository('Acme/App');
    }

    public function test_a_network_mask_must_be_a_number(): void
    {
        $this->expectException(InvalidArgument::class);

        HostValues::addressOrNetwork(TestNetwork::private(5).'/abc');
    }

    public function test_a_terminal_is_never_opened_in_the_host_s_namespaces(): void
    {
        $this->expectException(InvalidArgument::class);

        Root::action('host', 'shell', [], inHostNamespaces: true, terminal: true);
    }

    public function test_a_plain_command_is_not_a_broker_request(): void
    {
        $this->assertFalse(Root::isRequest(['ls', '-la']));
        $this->assertTrue(Root::isRequest(Privileged::docker()->containerImages()));
    }

    public function test_a_command_with_a_nul_byte_is_refused(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::accountDocker('acme')->exec('project-app-1', ['sh', "-c\0rm"]);
    }

    public function test_only_the_known_litespeed_verbs_are_sent(): void
    {
        $this->expectException(InvalidArgument::class);

        Privileged::engineServices('/opt/panelalpha/docker-compose.yml')->litespeedControl('stop');
    }
}
