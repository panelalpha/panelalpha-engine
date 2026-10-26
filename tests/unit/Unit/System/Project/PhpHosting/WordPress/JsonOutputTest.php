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
}
