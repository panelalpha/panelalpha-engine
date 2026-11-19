<?php

namespace Tests\Unit\System\Project\PhpHosting\WordPress;

use App\System\Project\PhpHosting\WordPress\Exception as WordPressException;
use App\System\Project\PhpHosting\WordPress\JsonOutput;
use PHPUnit\Framework\TestCase;

class JsonOutputTest extends TestCase
{
    public function test_plain_json_is_decoded(): void
    {
        $this->assertSame([['name' => 'akismet']], JsonOutput::decodeList('[{"name":"akismet"}]'));
    }

    public function test_php_notices_ahead_of_the_json_are_dropped(): void
    {
        $stdout = "PHP Deprecated:  Creation of dynamic property Foo::\$bar is deprecated in /home/alice/public_html/wp-content/plugins/foo/foo.php on line 12\n"
            . "Warning: Undefined array key \"x\" in /home/alice/public_html/wp-content/themes/t/functions.php on line 3\n"
            . '[{"name":"akismet","status":"active"}]';

        $this->assertSame([['name' => 'akismet', 'status' => 'active']], JsonOutput::decodeList($stdout));
    }

    public function test_output_that_is_not_json_is_a_500(): void
    {
        try {
            JsonOutput::decode("Error: something else\n");
            $this->fail('expected an exception');
        } catch (WordPressException $e) {
            $this->assertSame(500, $e->httpStatus);
            $this->assertSame('WP-CLI returned invalid JSON.', $e->getMessage());
        }
    }

    public function test_strip_noise_drops_php_diagnostics_and_blank_lines(): void
    {
        $output = "PHP Deprecated:  Foo is deprecated in /a/b.php on line 1\n\nWarning: Undefined array key \"x\" in /a/c.php on line 3\nError: real\n";

        $this->assertSame('Error: real', JsonOutput::stripNoise($output));
    }

    public function test_strip_noise_keeps_fatal_errors(): void
    {
        $line = 'PHP Fatal error:  Uncaught Error: boom in /a/b.php on line 4';

        $this->assertSame($line, JsonOutput::stripNoise($line."\n"));
    }

    public function test_strip_noise_drops_timestamped_php_diagnostics(): void
    {
        $output = "[17-Nov-2026 10:00:00 UTC] PHP Deprecated:  Foo is deprecated in /a/b.php on line 1\nError: real\n";

        $this->assertSame('Error: real', JsonOutput::stripNoise($output));
    }

    public function test_strip_noise_keeps_timestamped_fatal_errors(): void
    {
        $line = '[17-Nov-2026 10:00:00 UTC] PHP Fatal error:  Uncaught Error: boom in /a/b.php on line 4';

        $this->assertSame($line, JsonOutput::stripNoise($line."\n"));
    }

    public function test_php_fatal_error_ahead_of_the_json_is_dropped(): void
    {
        $stdout = "PHP Fatal error:  Uncaught Error: boom in /a/b.php on line 4\n"
            . '[{"name":"akismet"}]';

        $this->assertSame([['name' => 'akismet']], JsonOutput::decodeList($stdout));
    }
}
