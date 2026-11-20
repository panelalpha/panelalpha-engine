<?php

namespace Tests\Unit\Deploy\Template;

use App\Lib\Deploy\Template\Template;
use App\Lib\Deploy\Template\TemplateException;
use PHPUnit\Framework\TestCase;

/**
 * A template value that is neither a scalar nor a list cannot be rendered, and says which value it was.
 */
class TemplateValueTest extends TestCase
{
    public function test_an_object_value_is_refused_by_name_and_type(): void
    {
        $this->expectException(TemplateException::class);
        $this->expectExceptionMessage("Deploy template value 'image' is a stdClass, which cannot be rendered");

        Template::fromString("FROM {{ image }}\n")->render(['image' => new \stdClass]);
    }

    public function test_an_unreadable_file_names_its_path(): void
    {
        $this->assertSame('Deploy template could not be read: /x/y.stub', TemplateException::unreadableFile('/x/y.stub')->getMessage());
    }
}
