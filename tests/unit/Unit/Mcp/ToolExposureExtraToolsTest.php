<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ToolExposure;
use PHPUnit\Framework\TestCase;

class ToolExposureExtraToolsTest extends TestCase
{
    public function test_extra_tools_are_read_lowercased_without_blanks(): void
    {
        $this->assertSame(['project_create', 'metrics_latest'], (new ToolExposure(['tools' => ' Project_Create, metrics_latest ,, ']))->extraTools());
        $this->assertSame([], (new ToolExposure([]))->extraTools());
    }
}
