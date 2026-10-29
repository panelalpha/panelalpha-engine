<?php

namespace Tests\Unit;

use App\Http\Controllers\User\FtpAccountController;
use App\Models\Domain;
use OpenApi\Attributes as OA;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A traditional PHP hosting home has no /public_html at the top: each domain's
 * files are in its own /<domain>/public_html, so the documented directory is one.
 */
class FtpAccountDirectoryExampleTest extends TestCase
{
    public function test_the_documented_directory_is_the_example_domains_document_root(): void
    {
        $domain = new Domain(['domain' => $this->example('domain')]);

        $this->assertSame($domain->getDocumentRoot(), $this->example('directory'));
    }

    private function example(string $property): mixed
    {
        $post = (new ReflectionMethod(FtpAccountController::class, 'store'))->getAttributes(OA\Post::class)[0]->newInstance();
        $body = collect($post->requestBody->_unmerged)->first(fn (object $nested): bool => $nested instanceof OA\JsonContent);
        foreach ($body->properties as $candidate) {
            if ($candidate->property === $property) {
                return $candidate->example;
            }
        }
        $this->fail("No {$property} property in the FTP account create request body.");
    }
}
