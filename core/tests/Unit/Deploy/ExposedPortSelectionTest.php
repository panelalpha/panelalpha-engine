<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Detect\DockerfileFinder;
use PHPUnit\Framework\TestCase;

/**
 * `EXPOSE 22 3000` published SSH as the website.
 *
 * The parser read one number off one line -- `preg_match` with `EXPOSE\s+(\d+)`
 * -- so Gitea's Dockerfile, which exposes git-over-SSH and the web UI in that
 * order, resolved to 22. The engine bound `0.0.0.0:22->22/tcp`, pointed the
 * proxy at it, and the health probe answered
 *
 *     Received HTTP/0.9 when not allowed
 *
 * while sshd logged three dozen `banner exchange ... invalid format [preauth]`
 * connections from the proxy speaking HTTP at it. Gitea was listening on 3000
 * and healthy the entire time.
 *
 * Order still decides between two plausible ports -- the author wrote them in
 * that order -- but it no longer decides between a plausible one and SSH.
 */
class ExposedPortSelectionTest extends TestCase
{
    /** Gitea's, verbatim. */
    public function test_ssh_does_not_win_over_the_web_port(): void
    {
        $this->assertSame(3000, DockerfileFinder::exposedPortIn("FROM alpine\nEXPOSE 22 3000\n"));
    }

    /** The pre-existing behaviour: first plausible port on the first line. */
    public function test_the_first_plausible_port_still_wins(): void
    {
        $this->assertSame(8080, DockerfileFinder::exposedPortIn("EXPOSE 8080\nEXPOSE 9090\n"));
        $this->assertSame(8080, DockerfileFinder::exposedPortIn("EXPOSE 8080 9090\n"));
    }

    /** Separate lines, SSH first — the same trap in the other spelling. */
    public function test_ssh_on_its_own_line_is_skipped(): void
    {
        $this->assertSame(3000, DockerfileFinder::exposedPortIn("EXPOSE 22\nEXPOSE 3000\n"));
    }

    /** The protocol suffix is part of the syntax, not part of the number. */
    public function test_the_protocol_suffix_is_understood(): void
    {
        $this->assertSame(3000, DockerfileFinder::exposedPortIn("EXPOSE 22/tcp 3000/tcp\n"));
        $this->assertSame(53, DockerfileFinder::exposedPortIn("EXPOSE 53/udp\n"));
    }

    /** A datastore port is not a front door either; that list already exists. */
    public function test_a_datastore_port_is_skipped(): void
    {
        $this->assertSame(8000, DockerfileFinder::exposedPortIn("EXPOSE 5432 8000\n"));
    }

    /** Mail ports, for the same reason SSH is excluded. */
    public function test_mail_ports_are_skipped(): void
    {
        $this->assertSame(8080, DockerfileFinder::exposedPortIn("EXPOSE 25 587 993 8080\n"));
    }

    /**
     * A Dockerfile that really only exposes SSH still reports it. Reporting
     * nothing would be a different wrong answer, and the caller has other
     * evidence to weigh.
     */
    public function test_an_only_excluded_port_is_still_reported(): void
    {
        $this->assertSame(22, DockerfileFinder::exposedPortIn("EXPOSE 22\n"));
    }

    /** No EXPOSE, and a variable-substituted one, both answer nothing. */
    public function test_nothing_to_read_answers_null(): void
    {
        $this->assertNull(DockerfileFinder::exposedPortIn("FROM alpine\nCMD [\"sh\"]\n"));
        $this->assertNull(DockerfileFinder::exposedPortIn("EXPOSE \$PORT\n"));
    }

    /** Case and leading whitespace are not significant. */
    public function test_it_is_case_and_indent_insensitive(): void
    {
        $this->assertSame(3000, DockerfileFinder::exposedPortIn("  expose 22 3000\n"));
    }

    /** A number that is not a port is not taken for one. */
    public function test_an_out_of_range_number_is_ignored(): void
    {
        $this->assertSame(8080, DockerfileFinder::exposedPortIn("EXPOSE 99999 8080\n"));
    }

    /**
     * Medama's Dockerfile sets `ENV PORT=8080` and then `EXPOSE ${PORT}`.
     * Reading only literals, the engine saw no port at all, fell back to the
     * platform default 80, and published container port 80 while the app
     * bound 8080 -- so nothing ever answered. The number is in the file.
     */
    public function test_a_variable_the_file_defines_is_resolved(): void
    {
        $dockerfile = "FROM alpine\nENV PORT=8080 \\\n\tDB_HOST=/app/data/me.db\nEXPOSE \${PORT}\n";

        $this->assertSame(8080, DockerfileFinder::exposedPortIn($dockerfile));
    }

    /** An `ARG` default speaks for the port the same way an `ENV` does. */
    public function test_an_arg_default_is_resolved(): void
    {
        $this->assertSame(9000, DockerfileFinder::exposedPortIn("ARG PORT=9000\nEXPOSE \$PORT\n"));
    }

    /** The old `ENV NAME value` form, still in plenty of Dockerfiles. */
    public function test_the_space_separated_env_form_is_read(): void
    {
        $this->assertSame(6000, DockerfileFinder::exposedPortIn("ENV PORT 6000\nEXPOSE \$PORT\n"));
    }

    /** Quotes belong to the syntax, not to the value. */
    public function test_a_quoted_value_is_unwrapped(): void
    {
        $this->assertSame(7000, DockerfileFinder::exposedPortIn("ENV PORT=\"7000\"\nEXPOSE \$PORT\n"));
    }

    /**
     * An `ARG` with no default names a port the file does not know, and a
     * guess would be worse than the fallback the caller already has.
     */
    public function test_a_variable_with_no_value_is_still_nothing(): void
    {
        $this->assertNull(DockerfileFinder::exposedPortIn("ARG PORT\nEXPOSE \$PORT\n"));
    }

    /** Substitution does not smuggle SSH past the exclusion. */
    public function test_a_resolved_ssh_port_still_loses(): void
    {
        $this->assertSame(8080, DockerfileFinder::exposedPortIn("ENV P=22\nEXPOSE \$P\nEXPOSE 8080\n"));
    }

    /** rapidbay's torrent port, TCP and UDP, came before its web UI. */
    public function test_a_torrent_or_udp_port_does_not_win_over_the_web_port(): void
    {
        $rapidbay = "EXPOSE 6881\nEXPOSE 6881/udp\nEXPOSE 5000  # HTTP port\n";

        $this->assertSame(5000, DockerfileFinder::exposedPortIn($rapidbay));
        $this->assertSame(8080, DockerfileFinder::exposedPortIn("EXPOSE 7000/udp 8080\n"));
        $this->assertSame([6881, 5000], DockerfileFinder::exposedPortsIn($rapidbay));
    }
}
