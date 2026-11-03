<?php

namespace Tests\Unit\Overlay;

use App\Http\Requests\OverlaySetRequest;
use App\Http\Requests\ProjectOverlaySetRequest;
use App\Lib\Overlay\Overlay;
use App\Lib\Overlay\OverlayPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class OverlayRequestsTest extends TestCase
{
    public function test_the_global_overlay_needs_html_and_a_known_position(): void
    {
        $rules = (new OverlaySetRequest)->rules();

        $this->assertFalse(Validator::make(['html' => '<p>x</p>'], $rules)->fails());
        $this->assertFalse(Validator::make(['html' => '<p>x</p>', 'position' => 'head_end'], $rules)->fails());
        $this->assertTrue(Validator::make([], $rules)->fails());
        $this->assertTrue(Validator::make(['html' => '<p>x</p>', 'position' => 'top'], $rules)->fails());
        $this->assertTrue(Validator::make(['html' => "a\0b"], $rules)->fails());
        $this->assertTrue(Validator::make(['html' => str_repeat('a', Overlay::MAX_HTML_BYTES + 1)], $rules)->fails());
    }

    public function test_a_project_body_may_be_empty_and_show_global_must_be_a_boolean(): void
    {
        $rules = (new ProjectOverlaySetRequest)->rules();

        $this->assertFalse(Validator::make([], $rules)->fails());
        $this->assertFalse(Validator::make(['html' => null, 'show_global' => false], $rules)->fails());
        $this->assertTrue(Validator::make(['show_global' => 'nope'], $rules)->fails());
    }

    public function test_the_project_request_builds_the_overlay_it_was_given(): void
    {
        $request = $this->validated(ProjectOverlaySetRequest::class, ['html' => '<p>x</p>', 'position' => 'head_end', 'show_global' => false]);

        $this->assertEquals(new Overlay('<p>x</p>', OverlayPosition::HeadEnd), $request->overlay());
        $this->assertFalse($request->showGlobal());
    }

    public function test_an_empty_project_request_means_no_own_overlay_and_the_global_one_shown(): void
    {
        $request = $this->validated(ProjectOverlaySetRequest::class, []);

        $this->assertNull($request->overlay());
        $this->assertTrue($request->showGlobal());
    }

    public function test_the_global_request_defaults_to_the_end_of_the_body(): void
    {
        $request = $this->validated(OverlaySetRequest::class, ['html' => '<p>x</p>']);

        $this->assertEquals(new Overlay('<p>x</p>'), $request->overlay());
    }

    /**
     * @template T of FormRequest
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $body
     * @return T
     */
    private function validated(string $class, array $body): FormRequest
    {
        $request = $class::create('/overlay', 'PUT', $body);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request;
    }
}
