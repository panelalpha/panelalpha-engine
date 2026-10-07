<?php

namespace Tests\Unit\Routing;

use App\Providers\RouteServiceProvider;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

// Debug and documentation routes that answered without a token.
class UnauthenticatedRoutesTest extends TestCase
{
    /** @return list<string> */
    private function uris(): array
    {
        $uris = [];
        foreach (Route::getRoutes() as $route) {
            $uris[] = $route->uri();
        }

        return $uris;
    }

    // Re-runs the app's own route loading as it would boot in $env.
    private function reloadRoutesAs(string $env, bool $debug): void
    {
        $this->app['env'] = $env;
        config(['app.debug' => $debug]);
        $this->app['router']->setRoutes(new RouteCollection());

        $provider = new RouteServiceProvider($this->app);
        $provider->boot();
        (fn () => $this->loadRoutes())->call($provider);
    }

    public function test_request_docs_is_off_by_default(): void
    {
        $this->assertFalse(config('request-docs.enabled'));

        foreach ($this->uris() as $uri) {
            $this->assertStringStartsNotWith('request-docs', $uri);
        }
    }

    public function test_request_docs_asks_for_a_token_when_enabled(): void
    {
        $this->assertContains('auth:api', config('request-docs.middlewares'));
    }

    public function test_openapi_document_is_behind_the_swagger_switch(): void
    {
        $route = Route::getRoutes()->getByName('l5-swagger.default.docs');

        $this->assertNotNull($route);
        $this->assertContains('swagger.enabled', $route->gatherMiddleware());
        $this->get('/api/documentation/openapi.json')->assertNotFound();
    }

    public function test_production_with_debug_on_registers_no_log_viewer_or_metrics(): void
    {
        $this->reloadRoutesAs('production', true);
        $uris = $this->uris();

        $this->assertContains('vault/{token}', $uris);
        $this->assertNotContains('logs', $uris);
        $this->assertNotContains('metrics', $uris);
    }

    public function test_local_debug_keeps_the_log_viewer(): void
    {
        $this->reloadRoutesAs('local', true);

        $this->assertContains('logs', $this->uris());
    }
}
