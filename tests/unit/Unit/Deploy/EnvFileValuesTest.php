<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\Env\EnvFileValues;
use PHPUnit\Framework\TestCase;

class EnvFileValuesTest extends TestCase
{
    public function test_only_the_changed_value_is_rewritten_and_every_other_byte_is_kept(): void
    {
        $contents = "# keys\r\n"
            ."export APP_URL=https://old.test   # the public address\r\n"
            ."  KEY = 'kept'\r\n"
            ."\r\n"
            .'NAME="My \"App\"" # quoted'."\r\n"
            .'OTHER=old.test';

        $this->assertSame(
            "# keys\r\n"
            ."export APP_URL=https://new.test   # the public address\r\n"
            ."  KEY = 'kept'\r\n"
            ."\r\n"
            .'NAME="My \"App\"" # quoted'."\r\n"
            .'OTHER=new.test',
            EnvFileValues::replaced($contents, self::renaming())
        );
    }

    public function test_a_value_spanning_lines_and_the_lines_it_spans_are_left_alone(): void
    {
        $contents = "JWT_KEY=\"-----BEGIN KEY-----\nAPP_URL=https://old.test\n\\\"still inside\n-----END KEY-----\"\n"
            ."CERT='line one\nhttps://old.test'\n"
            ."APP_URL=https://old.test\n";

        $this->assertSame(
            "JWT_KEY=\"-----BEGIN KEY-----\nAPP_URL=https://old.test\n\\\"still inside\n-----END KEY-----\"\n"
            ."CERT='line one\nhttps://old.test'\n"
            ."APP_URL=https://new.test\n",
            EnvFileValues::replaced($contents, self::renaming())
        );
    }

    public function test_a_value_is_read_and_written_in_the_quoting_its_line_had(): void
    {
        $contents = "SINGLE='old\$1'\nDOUBLE=\"old\\\"x\"\nBARE=old\nBARE_SPACED=old\n";
        $replace = static fn (string $key, string $value): string => match ($key) {
            'SINGLE' => $value === 'old$1' ? 'new$1' : $value,
            'DOUBLE' => $value === 'old"x' ? 'new"x' : $value,
            'BARE' => 'new',
            'BARE_SPACED' => 'new value',
            default => $value,
        };

        $this->assertSame(
            "SINGLE='new\$1'\nDOUBLE=\"new\\\"x\"\nBARE=new\nBARE_SPACED=\"new value\"\n",
            EnvFileValues::replaced($contents, $replace)
        );
    }

    public function test_a_single_quoted_value_that_needs_double_quotes_keeps_its_dollar_literal(): void
    {
        $replace = static fn (string $key, string $value): string => "it's \$x";

        $this->assertSame("K=\"it's \$\$x\"\n", EnvFileValues::replaced("K='old'\n", $replace));
    }

    public function test_a_file_with_nothing_to_change_comes_back_identical(): void
    {
        $contents = "# only comments\nnot an assignment\nKEY=\"unterminated\n";

        $this->assertSame($contents, EnvFileValues::replaced($contents, self::renaming()));
    }

    /** @return \Closure(string, string): string */
    private static function renaming(): \Closure
    {
        return static fn (string $key, string $value): string => str_replace('old.test', 'new.test', $value);
    }
}
