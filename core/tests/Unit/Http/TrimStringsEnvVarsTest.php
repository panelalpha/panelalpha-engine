<?php

namespace Tests\Unit\Http;

use App\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Env var values reach the app as sent; everything else is still trimmed.
 */
class TrimStringsEnvVarsTest extends TestCase
{
    public function test_env_var_values_keep_their_surrounding_whitespace(): void
    {
        $request = $this->trimmed([
            'name' => '  shop  ',
            'env_vars' => ['SECRET' => "  s3cret \n", 'BLANK' => '   '],
        ]);

        $this->assertSame("  s3cret \n", $request->input('env_vars.SECRET'));
        $this->assertSame('   ', $request->input('env_vars.BLANK'));
        $this->assertSame('shop', $request->input('name'));
    }

    /** @param array<string, mixed> $body */
    private function trimmed(array $body): Request
    {
        $request = Request::create('/api/projects/shop/rebuild', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));

        return (new TrimStrings())->handle($request, static fn (Request $r): Request => $r);
    }
}
