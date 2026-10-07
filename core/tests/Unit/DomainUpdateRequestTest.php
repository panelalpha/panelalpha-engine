<?php

namespace Tests\Unit;

use App\Http\Requests\DomainUpdateRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DomainUpdateRequestTest extends TestCase
{
    private function rules(): array
    {
        return (new DomainUpdateRequest())->rules();
    }

    public function test_document_root_alone_passes(): void
    {
        $this->assertFalse(Validator::make(['document_root' => '/public_html'], $this->rules())->fails());
    }

    public function test_redirect_enabled_alone_passes(): void
    {
        $this->assertFalse(Validator::make(['redirect_enabled' => false], $this->rules())->fails());
    }

    public function test_force_https_redirect_alone_passes(): void
    {
        $this->assertFalse(Validator::make(['force_https_redirect' => true], $this->rules())->fails());
    }

    public function test_an_empty_body_passes_validation(): void
    {
        $this->assertFalse(Validator::make([], $this->rules())->fails());
    }

    public function test_a_full_body_still_passes(): void
    {
        $this->assertFalse(Validator::make([
            'document_root' => '/public_html',
            'redirect_enabled' => true,
            'redirect_url' => 'https://example.com',
            'force_https_redirect' => true,
        ], $this->rules())->fails());
    }

    public function test_a_non_boolean_redirect_enabled_still_fails(): void
    {
        $this->assertTrue(Validator::make(['redirect_enabled' => 'yes-please'], $this->rules())->fails());
    }

    public function test_a_document_root_escaping_the_account_still_fails(): void
    {
        $this->assertTrue(Validator::make(['document_root' => '/public_html/../../etc'], $this->rules())->fails());
    }

    public function test_ordinary_document_roots_pass(): void
    {
        foreach (['/', '/public_html', '/example.com/public_html', '/xn--bcher-kva.de/web_root-2/', '/a/b~c/d@e+f,g=h'] as $root) {
            $this->assertFalse(Validator::make(['document_root' => $root], $this->rules())->fails(), $root);
        }
    }

    // The vhost templates write the root unquoted, so anything that
    // ends a directive there must never reach them.
    public function test_characters_that_end_a_config_directive_fail(): void
    {
        foreach ([
            'https://example.com/wp-admin',
            "/public_html\n",
            "/public_html\nX",
            "/public_html\r",
            '/public html',
            "/public\thtml",
            '/public_html;',
            '/public_html{',
            '/public_html}',
            '/pub"lic',
            "/pub'lic",
            '/pub<lic>',
            '/pub\\lic',
            '/pub$host',
            '/pub#lic',
            '//',
            'public_html',
        ] as $root) {
            $this->assertTrue(Validator::make(['document_root' => $root], $this->rules())->fails(), (string) json_encode($root));
        }
    }
}
