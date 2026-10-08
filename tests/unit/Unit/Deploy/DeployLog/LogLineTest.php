<?php

namespace Tests\Unit\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\LogLine;
use PHPUnit\Framework\TestCase;

/**
 * Turning subprocess output into something safe to show a customer.
 *
 * Deploy logs are read in the panel, and applications print their connection
 * strings verbatim when a connection fails - including the ones the engine
 * generated itself, with the password it just chose. Nothing downstream
 * redacts, so anything that gets past here is in the customer's browser.
 */
class LogLineTest extends TestCase
{
    public function test_a_password_in_a_url_is_redacted(): void
    {
        $this->assertSame(
            'could not connect to postgres://***@db:5432/shop',
            LogLine::sanitize('could not connect to postgres://shop:s3cret@db:5432/shop')
        );
    }

    public function test_every_scheme_is_redacted_not_only_http(): void
    {
        // The ones that actually appear in a failing deploy are database and
        // queue URLs, not web ones.
        foreach (['mysql', 'redis', 'amqp', 'mongodb+srv', 'https'] as $scheme) {
            $this->assertSame(
                "{$scheme}://***@host/db",
                LogLine::sanitize("{$scheme}://user:pw@host/db"),
                $scheme
            );
        }
    }

    public function test_a_username_alone_is_still_redacted(): void
    {
        // The username is the account name; on its own it is half a credential.
        $this->assertSame('redis://***@cache:6379', LogLine::sanitize('redis://admin@cache:6379'));
    }

    public function test_a_url_with_no_credentials_is_left_readable(): void
    {
        // Redacting these would make the log useless for its actual purpose.
        $this->assertSame(
            'GET https://api.example.com/v1/status',
            LogLine::sanitize('GET https://api.example.com/v1/status')
        );
    }

    public function test_a_bearer_token_is_redacted(): void
    {
        $this->assertSame(
            'Authorization: Bearer ***',
            LogLine::sanitize('Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.abc.def')
        );
    }

    public function test_ansi_escapes_are_stripped(): void
    {
        // npm and composer colour their output; the raw escapes render as
        // mojibake in the panel.
        $this->assertSame('added 214 packages', LogLine::sanitize("\x1B[32madded 214 packages\x1B[0m"));
        $this->assertSame('building', LogLine::sanitize("\x1B]0;title\x07building"));
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('done', LogLine::sanitize("  done \t"));
    }

    public function test_a_progress_rewrite_keeps_only_its_final_state(): void
    {
        // docker rewrites one line with \r. Storing the whole thing would put
        // every intermediate percentage in the log.
        $this->assertSame(
            'Downloading 100%',
            LogLine::lastOverwrite("Downloading 10%\rDownloading 60%\rDownloading 100%")
        );
    }

    public function test_a_line_that_was_never_rewritten_is_unchanged(): void
    {
        $this->assertSame('added 214 packages', LogLine::lastOverwrite('added 214 packages'));
    }

    public function test_a_very_long_line_is_truncated_visibly(): void
    {
        // A single composer error can run to megabytes, and the ellipsis is
        // how a reader knows the message did not simply end there.
        $truncated = LogLine::truncate(str_repeat('x', LogLine::MAX_LENGTH + 100));

        $this->assertSame(LogLine::MAX_LENGTH + 3, strlen($truncated));
        $this->assertStringEndsWith('…', $truncated);
    }

    public function test_a_line_within_the_limit_is_untouched(): void
    {
        $message = str_repeat('x', LogLine::MAX_LENGTH);

        $this->assertSame($message, LogLine::truncate($message));
    }
}
