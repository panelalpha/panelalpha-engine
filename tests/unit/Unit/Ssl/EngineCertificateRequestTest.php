<?php

namespace Tests\Unit\Ssl;

use App\Lib\Ssl\EngineCertificateRequest;
use PHPUnit\Framework\TestCase;

/**
 * The certificate script writes for a terminal; this output goes into JSON.
 *
 * It matters because the script's own sentence is the answer: "does not
 * resolve. Point an A record at 203.0.113.7 first" is what the caller needs
 * to read, and wrapping it in escape codes is how a useful message becomes
 * something a panel renders as mojibake.
 */
class EngineCertificateRequestTest extends TestCase
{
    public function test_colour_codes_are_stripped_and_the_sentence_survives(): void
    {
        $coloured = "\e[32mCertificate domain: panel.example.com\e[39m\n"
            . "\e[31mpanel.example.com does not resolve. Point an A record at 203.0.113.7 first\e[39m";

        $this->assertSame(
            "Certificate domain: panel.example.com\n"
            . 'panel.example.com does not resolve. Point an A record at 203.0.113.7 first',
            EngineCertificateRequest::readable($coloured)
        );
    }

    public function test_output_without_colour_is_untouched_apart_from_its_edges(): void
    {
        $this->assertSame(
            "The dry run was successful.\nSaving debug log",
            EngineCertificateRequest::readable("\n  The dry run was successful.\nSaving debug log  \n\n")
        );
    }

    public function test_nothing_at_all_is_an_empty_string_not_a_stray_newline(): void
    {
        $this->assertSame('', EngineCertificateRequest::readable("\n"));
        $this->assertSame('', EngineCertificateRequest::readable("\e[32m\e[39m"));
    }
}
