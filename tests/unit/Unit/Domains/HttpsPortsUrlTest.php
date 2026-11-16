<?php

namespace Tests\Unit\Domains;

use App\Lib\Deploy\Compose\ComposePlaceholders;
use App\Lib\Domains\HttpsPorts;
use App\Lib\Project\ProjectOverview;
use App\Models\Domain;
use App\System\Project\Dind\Networking;
use Tests\Support\InMemoryDatabase;
use Tests\TestCase;

/**
 * The address a project's app is told, and the one the API reports, name the main domain's main HTTPS port.
 */
class HttpsPortsUrlTest extends TestCase
{
    use InMemoryDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootInMemoryDatabase();
    }

    public function test_a_domain_on_443_alone_keeps_the_url_it_always_had(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $domain = $this->makeMainDomain($user, 'shop.example.test');

        $this->assertSame([443], HttpsPorts::of($domain));
        $this->assertSame('', HttpsPorts::suffix($domain));
        $this->assertSame('https://shop.example.test', Networking::publicUrlOf($user));
        $this->assertSame('https://shop.example.test', ProjectOverview::publicUrl($user));
    }

    public function test_the_main_port_is_in_the_url_and_the_host(): void
    {
        $user = $this->makeUser('shop', ['template' => 'dind'], 'shop.example.test');
        $domain = $this->makeMainDomain($user, 'shop.example.test');
        $domain->setDetails([HttpsPorts::PORTS_KEY => [443, 8443], HttpsPorts::MAIN_KEY => 8443]);
        $domain->save();

        $this->assertSame('https://shop.example.test:8443', Networking::publicUrlOf($user));
        $this->assertSame('https://shop.example.test:8443', ProjectOverview::publicUrl($user));
        $this->assertSame('shop.example.test:8443', ComposePlaceholders::publicHostOf('https://shop.example.test:8443'));
        $this->assertSame('shop.example.test', ComposePlaceholders::publicHostOf('https://shop.example.test'));

        $filled = ComposePlaceholders::fill(['services' => ['app' => ['image' => 'x']]], 'seed', 'https://shop.example.test:8443');
        $this->assertSame('shop.example.test:8443', $filled['compose']['services']['app']['environment']['PA_PUBLIC_HOST']);
    }

    public function test_a_stored_main_port_that_is_not_listed_falls_back_to_the_first_one(): void
    {
        $domain = new Domain(['details' => [HttpsPorts::PORTS_KEY => [9443, 8443], HttpsPorts::MAIN_KEY => 443]]);

        $this->assertSame(9443, HttpsPorts::main($domain));
        $this->assertTrue(HttpsPorts::hasExtra($domain));
    }

    public function test_held_lists_each_port_besides_443_with_the_names_on_it(): void
    {
        $shop = $this->makeMainDomain($this->makeUser('shop', [], 'shop.example.test'), 'shop.example.test');
        $blog = $this->makeMainDomain($this->makeUser('blog', [], 'blog.example.test'), 'blog.example.test');
        $this->makeMainDomain($this->makeUser('plain', [], 'plain.example.test'), 'plain.example.test');
        $shop->setDetails([HttpsPorts::PORTS_KEY => [443, 8443], HttpsPorts::MAIN_KEY => 8443]);
        $shop->save();
        $blog->setDetails([HttpsPorts::PORTS_KEY => [8443, 9443], HttpsPorts::MAIN_KEY => 8443]);
        $blog->save();

        $this->assertSame([8443 => ['shop.example.test', 'blog.example.test'], 9443 => ['blog.example.test']], HttpsPorts::held());
        $this->assertSame(['blog.example.test'], HttpsPorts::namesOn(8443, (int) $shop->id));
    }
}
