<?php

namespace Tests\Unit\Files;

use App\Http\Requests\Files\ThumbnailRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The thumbnail geometry and fit are query parameters a grid sends on every
 * cell, so their defaults and bounds are the contract the panel codes against.
 */
class ThumbnailRequestTest extends TestCase
{
    public function test_geometry_and_fit_have_their_documented_defaults(): void
    {
        $validated = $this->validated(['path' => 'photo.jpg']);

        $this->assertSame('photo.jpg', $validated['path']);
        $this->assertSame(176, (int) $validated['width']);
        $this->assertSame(176, (int) $validated['height']);
        $this->assertSame('cover', $validated['fit']);
    }

    public function test_height_defaults_to_width(): void
    {
        $validated = $this->validated(['path' => 'photo.jpg', 'width' => 100]);

        $this->assertSame(100, (int) $validated['height']);
    }

    public function test_a_supplied_height_is_kept(): void
    {
        $validated = $this->validated(['path' => 'photo.jpg', 'width' => 100, 'height' => 50]);

        $this->assertSame(50, (int) $validated['height']);
    }

    public function test_width_below_the_minimum_is_refused(): void
    {
        $this->assertArrayHasKey('width', $this->errors(['path' => 'photo.jpg', 'width' => 8]));
    }

    public function test_width_above_the_maximum_is_refused(): void
    {
        $this->assertArrayHasKey('width', $this->errors(['path' => 'photo.jpg', 'width' => 2048]));
    }

    public function test_a_fit_that_is_not_cover_or_contain_is_refused(): void
    {
        $this->assertArrayHasKey('fit', $this->errors(['path' => 'photo.jpg', 'fit' => 'stretch']));
    }

    public function test_a_missing_path_is_refused(): void
    {
        $this->assertArrayHasKey('path', $this->errors([]));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validated(array $payload): array
    {
        $request = $this->request($payload);
        $request->validateResolved();

        return $request->validated();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, string>>
     */
    private function errors(array $payload): array
    {
        try {
            $this->request($payload)->validateResolved();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function request(array $payload): FormRequest
    {
        $request = ThumbnailRequest::create('/', 'GET', $payload);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));

        return $request;
    }
}
