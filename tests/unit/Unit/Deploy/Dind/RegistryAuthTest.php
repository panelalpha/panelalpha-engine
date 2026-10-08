<?php

namespace Tests\Unit\Deploy\Dind;

use App\Lib\Deploy\Dind\RegistryAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RegistryAuthTest extends TestCase
{
    public function test_each_line_is_one_login_and_hosts_are_normalised(): void
    {
        $auth = RegistryAuth::parse("# ours\nhttps://GHCR.io/ acme tok1\n\nregistry.example.com:5000  bot  tok2\nindex.docker.io me tok3");

        $this->assertSame(['ghcr.io', 'registry.example.com:5000', 'docker.io'], $auth->hosts());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function badValues(): array
    {
        return [
            'two fields' => ['ghcr.io acme', 'line 1'],
            'four fields' => ["ghcr.io a b\nghcr.io a b c", 'line 2'],
            'not a host' => ['ghcr.io/acme user tok', 'not a registry host'],
            'listed twice' => ["ghcr.io a b\nGHCR.IO c d", 'listed twice'],
        ];
    }

    #[DataProvider('badValues')]
    public function test_a_line_it_cannot_read_is_named(string $value, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        RegistryAuth::parse($value);
    }

    public function test_a_stored_value_that_no_longer_parses_means_no_logins(): void
    {
        $this->assertTrue(RegistryAuth::fromStored('ghcr.io half')->isEmpty());
        $this->assertTrue(RegistryAuth::fromStored(null)->isEmpty());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function references(): array
    {
        return [
            'official image' => ['nginx:1.27', 'docker.io'],
            'hub namespace' => ['acme/app:1', 'docker.io'],
            'ghcr' => ['ghcr.io/acme/app:1', 'ghcr.io'],
            'port' => ['registry.example.com:5000/app@sha256:' . str_repeat('a', 64), 'registry.example.com:5000'],
            'localhost' => ['localhost/app', 'localhost'],
            'hub spelled out' => ['registry-1.docker.io/acme/app', 'docker.io'],
        ];
    }

    #[DataProvider('references')]
    public function test_the_registry_of_an_image_is_found_the_way_docker_finds_it(string $image, string $registry): void
    {
        $this->assertSame($registry, RegistryAuth::registryFor($image));
    }

    public function test_covers_only_images_on_a_registry_it_logs_in_to(): void
    {
        $auth = RegistryAuth::parse('ghcr.io acme tok');

        $this->assertTrue($auth->covers('ghcr.io/acme/private:1'));
        $this->assertFalse($auth->covers('ghcr.io.evil.example/acme/private:1'));
        $this->assertFalse($auth->covers('nginx:1.27'));
    }

    public function test_the_client_config_is_what_docker_login_writes(): void
    {
        $config = json_decode(RegistryAuth::parse("ghcr.io acme tok1\ndocker.io me tok2")->dockerConfigJson(), true);

        $this->assertSame([
            'ghcr.io' => ['auth' => base64_encode('acme:tok1')],
            RegistryAuth::DOCKER_HUB_KEY => ['auth' => base64_encode('me:tok2')],
        ], $config['auths']);
    }

    public function test_secrets_cover_the_token_and_its_encoded_form(): void
    {
        $this->assertSame(
            ['tok1', base64_encode('acme:tok1')],
            RegistryAuth::parse('ghcr.io acme tok1')->secrets()
        );
    }
}
