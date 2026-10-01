<?php

namespace Tests\Unit\Http;

use App\Http\Controllers\UserController;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `{"env_vars": {"KEY": ""}}` removes one stored override. The global
 * ConvertEmptyStringsToNull middleware delivers that `""` as null.
 */
class EnvVarsRemovalTest extends TestCase
{
    public function test_an_empty_value_sent_over_http_removes_that_key_only(): void
    {
        $request = Request::create('/api/projects/plex/rebuild', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['env_vars' => ['PLEX_CLAIM' => '']]));

        $incoming = (new ConvertEmptyStringsToNull)->handle(
            $request,
            static fn (Request $r) => $r->input('env_vars'),
        );
        $this->assertSame(['PLEX_CLAIM' => null], $incoming, 'the middleware still nulls the empty string');

        $merged = $this->mergedEnvVars($incoming, ['PLEX_CLAIM' => 'claim-old', 'TZ' => 'UTC']);

        $this->assertSame(['TZ' => 'UTC'], $merged);
    }

    public function test_a_value_is_still_set(): void
    {
        $this->assertSame(
            ['PLEX_CLAIM' => 'claim-new', 'TZ' => 'UTC'],
            $this->mergedEnvVars(['PLEX_CLAIM' => 'claim-new'], ['PLEX_CLAIM' => 'claim-old', 'TZ' => 'UTC']),
        );
    }

    /**
     * @param array<string, string> $stored
     * @return array<string, string>
     */
    private function mergedEnvVars(mixed $incoming, array $stored): array
    {
        $controller = (new \ReflectionClass(UserController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(UserController::class, 'mergedEnvVars');

        return $method->invoke($controller, $incoming, $stored);
    }
}
