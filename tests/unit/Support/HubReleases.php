<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The PanelAlpha Hub's public release list, answered from a queue of engine versions; null is a Hub that is down. */
final class HubReleases
{
    public const HUB = 'https://hub.example.test';

    public const URL = self::HUB.'/api/v1/public/releases';

    /** @param  list<?string>  $versions */
    public static function fake(array $versions): void
    {
        config(['hub.url' => self::HUB]);
        Http::preventStrayRequests();
        $sequence = Http::sequence();
        foreach ($versions as $version) {
            $version === null
                ? $sequence->push(['message' => 'Server Error'], 503)
                : $sequence->push(self::body($version));
        }
        Http::fake([self::URL => $sequence]);
    }

    /** @return array<string, mixed> */
    public static function body(string $version): array
    {
        return ['data' => [
            'engine' => [
                'product' => 'engine',
                'name' => 'PanelAlpha Engine',
                'latest' => [
                    'version' => $version,
                    'publishedAt' => '2026-11-19',
                    'title' => "Version {$version}",
                    'url' => 'https://engine.panelalpha.com/changelog',
                    'notes' => "## New Features\n\n- Something new.",
                ],
            ],
            'multi-server-business' => ['product' => 'multi-server-business', 'latest' => ['version' => '1.9.8.4']],
        ]];
    }

    public static function asked(): int
    {
        return count(Http::recorded(fn (Request $request) => $request->url() === self::URL));
    }
}
