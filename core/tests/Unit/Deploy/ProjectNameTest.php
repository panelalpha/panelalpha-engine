<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\ProjectName;
use PHPUnit\Framework\TestCase;

class ProjectNameTest extends TestCase
{
    public function test_names_the_project_after_the_repository(): void
    {
        $this->assertSame(
            'n8n',
            ProjectName::base('https://github.com/n8n-io/n8n')
        );
    }

    public function test_falls_back_to_the_domains_first_label(): void
    {
        $this->assertSame('shop', ProjectName::base(null, 'shop.example.com'));
    }

    public function test_falls_back_to_the_recipe(): void
    {
        $this->assertSame('wordpress', ProjectName::base(null, null, 'wordpress'));
    }

    public function test_takes_a_plain_app_when_the_request_names_nothing(): void
    {
        $this->assertSame(ProjectName::FALLBACK, ProjectName::base());
        $this->assertSame(ProjectName::FALLBACK, ProjectName::base('', '', ''));
    }

    public function test_skips_a_source_that_yields_no_legal_name(): void
    {
        // A repository named "123" leaves nothing legal behind once leading
        // digits are dropped, so the domain is what names the project.
        $this->assertSame('blog', ProjectName::base('https://github.com/owner/123', 'blog.example.com'));
    }
}
