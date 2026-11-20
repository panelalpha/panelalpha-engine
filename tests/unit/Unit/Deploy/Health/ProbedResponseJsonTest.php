<?php

namespace Tests\Unit\Deploy\Health;

use App\Lib\Deploy\Health\ExplainerRegistry;
use App\Lib\Deploy\Health\Explainers\StaticEntryExplainer;
use App\Lib\Deploy\Health\ProbedResponse;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestNetwork;

/**
 * A response body read as a JSON object: once, and only when it is one; and the explainers found by id again after a flush.
 */
class ProbedResponseJsonTest extends TestCase
{
    private function response(string $body): ProbedResponse
    {
        return new ProbedResponse(200, $body, 'http://'.TestNetwork::LOOPBACK.':8000/');
    }

    public function test_an_object_body_is_json_and_is_decoded_once(): void
    {
        $response = $this->response('{"database":"connected"}');

        $this->assertTrue($response->isJson());
        $this->assertSame(['database' => 'connected'], $response->json());
        $this->assertSame($response->json(), $response->json());
    }

    public function test_an_empty_body_a_list_and_garbage_are_not_json_objects(): void
    {
        $this->assertFalse($this->response('')->isJson());
        $this->assertFalse($this->response('[1,2]')->isJson());
        $this->assertFalse($this->response('{not json')->isJson());
        $this->assertNull($this->response('')->json());
    }

    public function test_the_explainers_are_found_again_after_a_flush(): void
    {
        ExplainerRegistry::flush();

        $this->assertInstanceOf(StaticEntryExplainer::class, ExplainerRegistry::find('static-entry'));
        $this->assertNull(ExplainerRegistry::find('no-such-explainer'));
    }
}
