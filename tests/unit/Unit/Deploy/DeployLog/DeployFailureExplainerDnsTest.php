<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Findings\FindingCodes;
use PHPUnit\Framework\TestCase;

/** A registry name the build could not look up is a DNS fault on the server, not the git warning printed beside it. */
class DeployFailureExplainerDnsTest extends TestCase
{
    /** getarcaneapp/arcane, FROM ghcr.io, built by a docker client that had no resolver. */
    private const NO_RESOLVER = <<<'OUT'
#5 ERROR: failed to authorize: failed to fetch anonymous token: Get "https://ghcr.io/token?scope=repository%3Agetarcaneapp%2Fbase%3Apull&service=ghcr.io": dial tcp: lookup ghcr.io on [::1]:53: dial udp [::1]:53: connect: cannot assign requested address
#7 CANCELED
------
> [internal] load metadata for ghcr.io/getarcaneapp/base:distroless:
------
WARNING: current commit information was not captured by the build: git was not found in the system: exec: "git": executable file not found in $PATH
Dockerfile:76
--------------------
  76 | >>> FROM ${ARCANE_RUNTIME_BASE_IMAGE} AS runner-hardened
--------------------
failed to solve: failed to fetch anonymous token: Get "https://ghcr.io/token?scope=repository%3Agetarcaneapp%2Fbase%3Apull&service=ghcr.io": dial tcp: lookup ghcr.io on [::1]:53: dial udp [::1]:53: connect: cannot assign requested address
OUT;

    public function test_a_lookup_with_no_resolver_names_the_image_and_the_server_not_the_git_warning(): void
    {
        $this->assertSame([
            'rule' => 'build-dns-unavailable',
            'message' => 'The base image ghcr.io/getarcaneapp/base:distroless could not be downloaded: the build could '
                .'not look up ghcr.io, because no DNS server was configured where it was looked up: Go fell back to '
                .'[::1]:53, where none listens. This is a fault on the server, not in the project, and deploying again '
                .'will not help until it is fixed. Images from Docker Hub are not affected, as they come through the '
                .'server\'s registry mirror.',
        ], DeployFailureExplainer::match(self::NO_RESOLVER));
    }

    /** What compose prints on stderr, without the step's metadata block: the token's repository names the image. */
    public function test_the_stderr_summary_alone_names_the_image_by_its_token_s_repository(): void
    {
        $stderr = "Dockerfile:76\n--------------------\n  76 | >>> FROM \${ARCANE_RUNTIME_BASE_IMAGE} AS runner-hardened\n"
            ."--------------------\n".substr(self::NO_RESOLVER, (int) strrpos(self::NO_RESOLVER, 'failed to solve:'));

        $match = DeployFailureExplainer::match(FailureOutput::select($stderr));

        $this->assertSame('build-dns-unavailable', $match['rule'] ?? null);
        $this->assertStringStartsWith('The image ghcr.io/getarcaneapp/base could not be downloaded: the build could not look up '
            .'ghcr.io, because no DNS server was configured', $match['message']);
    }

    public function test_the_whole_log_s_failure_region_is_explained_the_same(): void
    {
        $this->assertSame('build-dns-unavailable', DeployFailureExplainer::match(FailureOutput::select(self::NO_RESOLVER))['rule'] ?? null);
    }

    public function test_a_refused_loopback_resolver_is_no_resolver_too(): void
    {
        $match = DeployFailureExplainer::match('failed to solve: failed to resolve source metadata for public.ecr.aws/docker/library/alpine:3.20: '
            .'failed to do request: Head "https://public.ecr.aws/v2/docker/library/alpine/manifests/3.20": dial tcp: '
            ."lookup public.ecr.aws on 127.0.0.1:53: read udp 127.0.0.1:40188->127.0.0.1:53: read: connection refused\n");

        $this->assertSame('build-dns-unavailable', $match['rule'] ?? null);
        $this->assertStringStartsWith('The base image public.ecr.aws/docker/library/alpine:3.20 could not be downloaded: the build '
            .'could not look up public.ecr.aws, because no DNS server was configured', $match['message']);
    }

    public function test_a_resolver_that_failed_to_answer_is_quoted_and_worth_a_retry(): void
    {
        $this->assertSame([
            'rule' => 'build-dns-unavailable',
            'message' => 'The build could not look up ghcr.io: the DNS server at 127.0.0.11 answered "server misbehaving". '
                .'This is a DNS problem on the server, not in the project; deploy again, and if it repeats, the server\'s '
                .'DNS needs fixing.',
        ], DeployFailureExplainer::match("dial tcp: lookup ghcr.io on 127.0.0.11:53: server misbehaving\n"));
    }

    public function test_a_name_that_does_not_exist_is_not_a_dns_fault(): void
    {
        $match = DeployFailureExplainer::match('failed to solve: failed to resolve source metadata for registry.example.invalid/app:1: '
            .'failed to do request: Head "https://registry.example.invalid/v2/app/manifests/1": dial tcp: '
            ."lookup registry.example.invalid on 127.0.0.11:53: no such host\n");

        $this->assertSame('base-image-unavailable', $match['rule'] ?? null);
    }

    public function test_it_is_a_failure_code_a_finding_can_carry(): void
    {
        $this->assertContains('build-dns-unavailable', DeployFailureExplainer::failureCodes());
        $this->assertContains('build-dns-unavailable', FindingCodes::all());
    }
}
