<?php

namespace Tests\Integration\OpenApi;

use App\OpenApi\OperationSummaries;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The token catalogues label each operation with its OpenAPI summary, read from the attributes rather than from a
 * generated document an install may not have. Both must say the same thing.
 */
class OperationSummariesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'storage/framework/testing/openapi-' . bin2hex(random_bytes(4));
        File::makeDirectory(base_path($this->dir . '/spec'), 0755, true);
        OperationSummaries::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path($this->dir));
        OperationSummaries::forget();
        parent::tearDown();
    }

    public function test_the_summaries_are_the_generated_documents(): void
    {
        config(['l5-swagger.defaults.paths.docs' => base_path($this->dir . '/spec')]);
        $this->assertSame(0, Artisan::call('l5-swagger:generate'));

        $document = json_decode(File::get(base_path($this->dir . '/spec/api-docs.json')), true);
        $summaries = [];

        foreach ($document['paths'] as $path => $item) {
            foreach ($item as $verb => $operation) {
                if (is_array($operation) && isset($operation['summary'])) {
                    $summaries[strtoupper($verb) . ' ' . $path] = $operation['summary'];
                }
            }
        }

        ksort($summaries);

        $this->assertSame($summaries, OperationSummaries::all());
    }

    public function test_an_undocumented_operation_has_no_summary(): void
    {
        $this->assertSame('Get a project', OperationSummaries::of('GET /projects/{username}'));
        $this->assertNull(OperationSummaries::of('GET /projects/{username}/source-inspection'));
    }
}
