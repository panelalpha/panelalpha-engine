<?php

namespace Tests\Feature;

use Tests\TestCase;


class ExampleTest extends TestCase
{
    public function test_example_request(): void
    {
        $response = $this->get('/');
        $response->assertStatus(204);
    }
}
